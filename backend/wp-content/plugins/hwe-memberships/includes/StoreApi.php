<?php
/**
 * Extensión `hwe-memberships` de la Store API y bloqueo de compra (spec §6.3 y §10.4).
 *
 * **Bloqueo por requisito de acceso.** Una línea cuyo requisito de acceso no cumple el usuario
 * (`HWE_Memberships_Access::lockReason()`) se rechaza en los tres puntos en los que WooCommerce
 * decide la compra: al añadir al carrito (`woocommerce_store_api_validate_add_to_cart`), en los
 * errores del carrito (`woocommerce_store_api_cart_errors`) y al crear el pedido de la Store API
 * (`woocommerce_store_api_checkout_update_order_from_request`). Se comprueba otra vez al crear
 * el pedido porque el nivel puede caducar, congelarse o retirarse con la línea ya en el carrito
 * (Foco de revisión 3). Los motivos son estables: `membership_required` y
 * `membership_early_access`, con el rango y el nivel en `data`, y la respuesta usa **409**: el
 * carrito no es inválido, entra en conflicto con el estado actual de la membresía (el BFF lo
 * traduce con ese mismo estado; se separa así del 400 de las reglas de compra de la Tarea 6).
 * Una variación se evalúa con el requisito de su producto padre.
 *
 * **Contrato de la extensión.** `extensions.hwe-memberships` del carrito expone:
 *  · `level`: `{id, slug, rank, name}` del nivel efectivo, o `null` (sin membresía o congelado);
 *  · `member_discount`: `{total, lines}`, importes en unidades MENORES de la moneda y como
 *    string; lo rellena la Tarea 12;
 *  · `free_delivery`: `{available, remaining, requested, applied, reason}`; lo rellena la Tarea
 *    13 y `requested` refleja únicamente lo que el cliente declaró;
 *  · `blocked_items`: lista de `{key, product_id, reason, min_rank, level_slug, level_name,
 *    opens_at}` de las líneas que hoy no puede comprar. `product_id` es el id comprable de la
 *    línea (la variación cuando la hay); el requisito se evalúa con su producto padre;
 *  · `purchase_notice`: `renewal`, `upgrade`, `scheduled` o `null`, para la línea de membresía
 *    del carrito. Compara el nivel comprado con el efectivo y con la cola: misma referencia es
 *    una renovación; subir de rango es una mejora; bajar de rango —o esperar detrás de una
 *    cobertura superior, incluida la cola— queda programado. Sin cobertura previa no hay aviso:
 *    la compra activa el nivel de inmediato.
 *
 * **Punto de extensión.** El filtro `hwe_memberships_store_api_cart_data` recibe el array
 * completo y un contexto (`cart`, `user_id`, `state`) para que las Tareas 12 y 13 aporten el
 * descuento y el envío gratis. Solo se aceptan sus ramas `member_discount` y `free_delivery`:
 * `level`, `blocked_items` y `purchase_notice` se recalculan siempre aquí para que un filtro no
 * pueda retirar una línea bloqueada.
 *
 * **Envío gratis.** El navegador solo declara «quiero usar un envío gratis» en el update
 * callback (`{free_delivery: bool}`); el valor se guarda en la sesión de WooCommerce y cualquier
 * otra clave o valor no booleano se ignora y se registra. Ningún importe ni decisión llega del
 * navegador. La petición es del carrito, no de la cuenta: se olvida cuando el pedido ya se creó
 * (y reservó lo suyo) y cuando el carrito se vacía, para que el siguiente carrito no herede un
 * envío gratis que el cliente no ha pedido. Con el módulo apagado no se registra la extensión ni
 * se valida nada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_StoreApi {

	/** Namespace de la extensión en `extensions` del carrito. */
	public const NAMESPACE_ID = 'hwe-memberships';

	/** Clave de la sesión de WooCommerce con la petición de envío gratis. */
	public const SESSION_FREE_DELIVERY = 'hwe_memberships_free_delivery';

	/** Filtro interno con el que las Tareas 12 y 13 aportan sus datos al contrato. */
	public const CART_DATA_FILTER = 'hwe_memberships_store_api_cart_data';

	/** Avisos de compra de la línea de membresía. */
	public const NOTICE_RENEWAL   = 'renewal';
	public const NOTICE_UPGRADE   = 'upgrade';
	public const NOTICE_SCHEDULED = 'scheduled';

	/** Motivos estables de bloqueo por requisito de acceso. */
	public const REASON_REQUIRED     = 'membership_required';
	public const REASON_EARLY_ACCESS = 'membership_early_access';

	/** Estado HTTP del conflicto entre el carrito y la membresía actual. */
	private const CONFLICT_STATUS = 409;

	/** Avisos ya emitidos en esta petición. */
	private static array $logged = array();

	/**
	 * Engancha la extensión y los tres validadores. Lo llama `HWE_Memberships::boot()`.
	 *
	 * El módulo arranca en `plugins_loaded` (prioridad 20), después de que WooCommerce dispare
	 * `woocommerce_blocks_loaded`: si ya ocurrió, la extensión se registra en el acto.
	 */
	public static function register(): void {
		if ( ! self::moduleActive() ) {
			return;
		}

		if ( function_exists( 'did_action' ) && did_action( 'woocommerce_blocks_loaded' ) ) {
			self::registerStoreApi();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'registerStoreApi' ) );
		}

		add_action( 'woocommerce_store_api_validate_add_to_cart', array( self::class, 'validateAddToCart' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( self::class, 'validateCart' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'validateOrderRequest' ), 10, 2 );

		// La petición de envío gratis vive en la sesión del carrito: se olvida cuando el pedido
		// ya se creó (y reservó lo suyo) y cuando el carrito se vacía, o el siguiente carrito
		// heredaría un envío gratis que el cliente no ha pedido.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'forgetRequest' ), 20, 1 );
		add_action( 'woocommerce_cart_emptied', array( self::class, 'forgetRequest' ), 20, 0 );
	}

	/** Declara `extensions.hwe-memberships` y el update callback del namespace. */
	public static function registerStoreApi(): void {
		if ( ! self::moduleActive() ) {
			return;
		}

		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) && class_exists( 'Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartSchema' ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace'       => self::NAMESPACE_ID,
					'data_callback'   => array( self::class, 'cartState' ),
					'schema_callback' => array( self::class, 'cartStateSchema' ),
				)
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE_ID,
					'callback'  => array( self::class, 'updateState' ),
				)
			);
		}
	}

	// ------------------------------------------------------------------------
	//  Update callback: solo el envío gratis declarado por el cliente
	// ------------------------------------------------------------------------

	/**
	 * Update callback del namespace. Acepta SOLO `{free_delivery: bool}`; cualquier otra clave
	 * o un valor que no sea booleano se ignora y se registra. La decisión de aplicarlo es de
	 * WordPress (Tarea 13), nunca del navegador.
	 *
	 * @param mixed $data Datos del endpoint `cart/extensions`.
	 */
	public static function updateState( mixed $data ): void {
		if ( ! self::moduleActive() ) {
			return;
		}

		if ( ! is_array( $data ) ) {
			self::log( 'el update callback de la extensión recibió ' . get_debug_type( $data ) . '; se ignora.' );
			return;
		}

		$ignored = array_values( array_diff( array_keys( $data ), array( 'free_delivery' ) ) );
		if ( array() !== $ignored ) {
			// No se escriben las claves del cliente en el log: pueden ser texto arbitrario.
			self::log(
				'el update callback de la extensión solo acepta free_delivery; se ignoran '
				. count( $ignored ) . ' clave(s) no admitida(s).'
			);
		}

		if ( ! array_key_exists( 'free_delivery', $data ) ) {
			return;
		}

		if ( ! is_bool( $data['free_delivery'] ) ) {
			self::log( 'free_delivery debe ser booleano; se conserva el valor anterior.' );
			return;
		}

		$session = self::session();
		if ( null === $session ) {
			self::log( 'no hay sesión de WooCommerce para guardar la petición de envío gratis.' );
			return;
		}

		$session->set( self::SESSION_FREE_DELIVERY, $data['free_delivery'] );
	}

	/** ¿El cliente pidió usar un envío gratis en este carrito? Solo lo lee WordPress. */
	public static function requestedFreeDelivery(): bool {
		if ( ! self::moduleActive() ) {
			return false;
		}

		$session = self::session();
		if ( null === $session ) {
			return false;
		}

		return (bool) $session->get( self::SESSION_FREE_DELIVERY, false );
	}

	/**
	 * Olvida la petición de envío gratis de la sesión. La petición es del carrito y no de la
	 * cuenta: el siguiente carrito no debe heredar un envío gratis que el cliente no pidió.
	 * Se llama tras procesar el pedido (cuando ya reservó, si tocaba) y al vaciar el carrito.
	 */
	public static function forgetRequest(): void {
		$session = self::session();
		if ( null === $session ) {
			return;
		}

		$session->set( self::SESSION_FREE_DELIVERY, false );
	}

	// ------------------------------------------------------------------------
	//  Contrato de la extensión
	// ------------------------------------------------------------------------

	/**
	 * Estado que viaja en `extensions.hwe-memberships`.
	 *
	 * @return array<string,mixed>
	 */
	public static function cartState(): array {
		$userId = self::currentUserId();
		$cart   = self::cart();
		$state  = self::memberState( $userId );

		$data = array(
			'level'            => self::levelData( $userId, $state ),
			'member_discount'  => array(
				'total' => '0',
				'lines' => (object) array(),
			),
			'free_delivery'    => array(
				'available' => false,
				'remaining' => 0,
				'requested' => self::requestedFreeDelivery(),
				'applied'   => false,
				'reason'    => null,
			),
			'blocked_items'    => self::blockedItems( $userId, $cart ),
			'purchase_notice'  => self::purchaseNotice( $userId, $cart, $state ),
		);

		if ( ! self::moduleActive() || ! function_exists( 'apply_filters' ) ) {
			return $data;
		}

		try {
			$filtered = apply_filters(
				self::CART_DATA_FILTER,
				$data,
				array(
					'cart'    => $cart,
					'user_id' => $userId,
					'state'   => $state,
				)
			);
		} catch ( \Throwable $e ) {
			self::log( 'el filtro ' . self::CART_DATA_FILTER . ' lanzó una excepción; se usa el estado calculado.' );
			return $data;
		}

		if ( ! is_array( $filtered ) ) {
			self::log( 'el filtro ' . self::CART_DATA_FILTER . ' debe devolver un array; se usa el estado calculado.' );
			return $data;
		}

		// `level`, `blocked_items` y `purchase_notice` son decisión de este módulo: un filtro
		// no puede retirar una línea bloqueada ni inventar un aviso. Las ramas económicas se
		// normalizan para conservar el contrato (booleanos e importes en unidades menores).
		$data['member_discount'] = array_key_exists( 'member_discount', $filtered )
			? self::normalizeDiscount( $filtered['member_discount'] )
			: $data['member_discount'];
		$data['free_delivery']   = array_key_exists( 'free_delivery', $filtered )
			? self::normalizeFreeDelivery( $filtered['free_delivery'], (bool) $data['free_delivery']['requested'] )
			: $data['free_delivery'];

		return $data;
	}

	/**
	 * Esquema de la extensión para la Store API.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function cartStateSchema(): array {
		$field = static function ( string $description, array $schema ): array {
			return array_merge(
				array(
					'description' => $description,
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				$schema
			);
		};

		return array(
			'level'           => $field(
				'Nivel efectivo del socio, o null si no tiene membresía o está congelado.',
				array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'id'   => array( 'type' => 'integer' ),
						'slug' => array( 'type' => 'string' ),
						'rank' => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
					),
				)
			),
			'member_discount' => $field(
				'Descuento de miembro: total y por línea, en unidades menores de la moneda.',
				array(
					'type'       => 'object',
					'properties' => array(
						'total' => array( 'type' => 'string' ),
						'lines' => array(
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'string' ),
						),
					),
				)
			),
			'free_delivery'   => $field(
				'Estado del envío gratis: disponibilidad, cupo restante, petición del cliente y motivo.',
				array(
					'type'       => 'object',
					'properties' => array(
						'available' => array( 'type' => 'boolean' ),
						'remaining' => array( 'type' => 'integer' ),
						'requested' => array( 'type' => 'boolean' ),
						'applied'   => array( 'type' => 'boolean' ),
						'reason'    => array( 'type' => array( 'string', 'null' ) ),
					),
				)
			),
			'blocked_items'   => $field(
				'Líneas del carrito que el usuario no puede comprar con su membresía actual.',
				array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'        => array( 'type' => 'string' ),
							'product_id' => array( 'type' => 'integer' ),
							'reason'     => array(
								'type' => 'string',
								'enum' => array( self::REASON_REQUIRED, self::REASON_EARLY_ACCESS ),
							),
							'min_rank'   => array( 'type' => 'integer' ),
							'level_slug' => array( 'type' => 'string' ),
							'level_name' => array( 'type' => 'string' ),
							'opens_at'   => array( 'type' => array( 'string', 'null' ) ),
						),
					),
				)
			),
			'purchase_notice' => $field(
				'Qué pasará al cobrar la línea de membresía: renovación, mejora, compra programada o nada.',
				array(
					'type' => array( 'string', 'null' ),
					'enum' => array( self::NOTICE_RENEWAL, self::NOTICE_UPGRADE, self::NOTICE_SCHEDULED, null ),
				)
			),
		);
	}

	// ------------------------------------------------------------------------
	//  Validación en la Store API
	// ------------------------------------------------------------------------

	/**
	 * Rechaza un alta cuyo requisito de acceso no cumple el usuario.
	 *
	 * @param mixed $cart    Carrito de la Store API (no se usa: el alta aún no está en él).
	 * @param mixed $request Petición con `id`, `variation_id` y `quantity`.
	 * @throws \Throwable Con el código `hwe_membership_required` o `hwe_membership_early_access`.
	 */
	public static function validateAddToCart( $cart, $request ): void {
		if ( ! self::moduleActive() || ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return;
		}

		$productId   = (int) $request->get_param( 'id' );
		$variationId = (int) $request->get_param( 'variation_id' );
		if ( $variationId > 0 ) {
			$productId = $variationId;
		}
		if ( $productId <= 0 ) {
			return;
		}

		$lock = self::lockReason( self::currentUserId(), self::accessProductId( $productId ) );
		if ( null === $lock ) {
			return;
		}

		throw self::error( $lock, array( 'product_id' => $productId ) );
	}

	/**
	 * Añade un error por cada línea bloqueada del carrito, antes de crear el pedido.
	 *
	 * @param mixed $errors Errores del carrito (`WP_Error`).
	 * @param mixed $cart   Carrito de la Store API.
	 */
	public static function validateCart( $errors, $cart ): void {
		if (
			! self::moduleActive()
			|| ! is_object( $errors )
			|| ! method_exists( $errors, 'add' )
		) {
			return;
		}

		$userId = self::currentUserId();
		foreach ( self::cartLines( $cart ) as $line ) {
			$lock = self::lockReason( $userId, self::accessProductId( $line['product_id'] ) );
			if ( null === $lock ) {
				continue;
			}

			$errors->add(
				'hwe_' . $lock['reason'],
				self::message( (string) $lock['reason'] ),
				array_merge(
					array(
						'status'   => self::CONFLICT_STATUS,
						'reason'   => (string) $lock['reason'],
						'level_id' => (int) ( $lock['level_id'] ?? 0 ),
					),
					self::lockData( $lock ),
					array( 'key' => $line['key'] )
				)
			);
		}
	}

	/**
	 * Revalida las líneas del pedido recién creado por la Store API. Una línea bloqueada
	 * cancela el checkout con `RouteException` (Foco de revisión 3).
	 *
	 * @param mixed $order   Pedido con sus líneas.
	 * @param mixed $request Petición del checkout (no se usa).
	 * @throws \Throwable Con el código `hwe_membership_required` o `hwe_membership_early_access`.
	 */
	public static function validateOrderRequest( $order, $request ): void {
		if ( ! self::moduleActive() || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$userId = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;

		foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}

			$productId = (int) $item->get_product_id();
			if ( method_exists( $item, 'get_variation_id' ) ) {
				$variationId = (int) $item->get_variation_id();
				if ( $variationId > 0 ) {
					$productId = $variationId;
				}
			}
			if ( $productId <= 0 ) {
				continue;
			}

			$lock = self::lockReason( $userId, self::accessProductId( $productId ) );
			if ( null === $lock ) {
				continue;
			}

			throw self::error(
				$lock,
				array(
					'product_id' => $productId,
					'item_id'    => method_exists( $item, 'get_id' ) ? (int) $item->get_id() : 0,
				)
			);
		}
	}

	// ------------------------------------------------------------------------
	//  Cálculo del estado
	// ------------------------------------------------------------------------

	/**
	 * Estado del socio, leído una sola vez por respuesta del carrito. `null` si no hay usuario
	 * o no se pudo leer (el contrato degrada sin inventar un nivel).
	 *
	 * @return array<string,mixed>|null
	 */
	private static function memberState( int $userId ): ?array {
		if ( $userId <= 0 || ! class_exists( 'HWE_Memberships_Members' ) ) {
			return null;
		}

		try {
			return HWE_Memberships_Members::state( $userId );
		} catch ( \Throwable $e ) {
			self::logOnce( 'state:' . $userId, 'no se pudo leer el estado del socio ' . $userId . '; se omite su nivel.' );
			return null;
		}
	}

	/**
	 * Nivel efectivo para el contrato. Congelado o sin membresía ⇒ `null`.
	 *
	 * @param array<string,mixed>|null $state Estado ya leído por `memberState()`.
	 * @return array{id:int,slug:string,rank:int,name:string}|null
	 */
	private static function levelData( int $userId, ?array $state ): ?array {
		if ( $userId <= 0 || null === $state ) {
			return null;
		}

		$levelId = (int) ( $state['level_id'] ?? 0 );
		if (
			HWE_Memberships_Members::STATUS_ACTIVE !== (string) ( $state['status'] ?? '' )
			|| $levelId <= 0
		) {
			return null;
		}

		$level = class_exists( 'HWE_Memberships_Levels' ) ? HWE_Memberships_Levels::get( $levelId ) : null;
		if ( null === $level ) {
			self::logOnce( 'level:' . $levelId, 'el nivel efectivo ' . $levelId . ' no se pudo leer; se publica solo su id y su rango.' );
		}

		return array(
			'id'   => $levelId,
			'slug' => (string) ( $level['slug'] ?? '' ),
			'rank' => (int) ( $level['rank'] ?? $state['rank'] ?? 0 ),
			'name' => (string) ( $level['name'] ?? '' ),
		);
	}

	/**
	 * Líneas del carrito que hoy no cumplen su requisito de acceso.
	 *
	 * @return list<array{key:string,product_id:int,reason:string,min_rank:int,level_slug:string,level_name:string,opens_at:?string}>
	 */
	private static function blockedItems( int $userId, mixed $cart ): array {
		$items = array();

		foreach ( self::cartLines( $cart ) as $line ) {
			$lock = self::lockReason( $userId, self::accessProductId( $line['product_id'] ) );
			if ( null === $lock ) {
				continue;
			}

			$items[] = array_merge(
				array(
					'key'        => $line['key'],
					'product_id' => $line['product_id'],
				),
				self::lockData( $lock )
			);
		}

		return $items;
	}

	/**
	 * Aviso de compra de la línea de membresía, comparando su nivel con el efectivo y la cola.
	 *
	 * @param array<string,mixed>|null $state Estado ya leído por `memberState()`.
	 */
	private static function purchaseNotice( int $userId, mixed $cart, ?array $state ): ?string {
		if ( $userId <= 0 || null === $state ) {
			return null;
		}

		$levelId = null;
		foreach ( self::cartLines( $cart ) as $line ) {
			$membership = self::membershipOf( $line['product_id'] );
			if ( null === $membership ) {
				continue;
			}
			$levelId = (int) $membership['level_id'];
			break;
		}

		if ( null === $levelId || $levelId <= 0 ) {
			return null;
		}

		$targetRank = class_exists( 'HWE_Memberships_Levels' ) ? HWE_Memberships_Levels::rankOf( $levelId ) : 0;
		if ( $targetRank < 1 ) {
			return null;
		}

		$active        = HWE_Memberships_Members::STATUS_ACTIVE === (string) ( $state['status'] ?? '' );
		$effectiveRank = $active ? (int) ( $state['rank'] ?? 0 ) : 0;
		$effectiveId   = $active ? (int) ( $state['level_id'] ?? 0 ) : 0;

		if ( $effectiveRank > 0 ) {
			if ( $effectiveId === $levelId ) {
				return self::NOTICE_RENEWAL;
			}
			if ( $targetRank > $effectiveRank && ! self::queueCovers( $state['queue'] ?? array(), $targetRank ) ) {
				return self::NOTICE_UPGRADE;
			}

			return self::NOTICE_SCHEDULED;
		}

		return self::queueCovers( $state['queue'] ?? array(), $targetRank )
			? self::NOTICE_SCHEDULED
			: null;
	}

	/**
	 * ¿La cola del socio ya tiene una concesión de rango igual o superior? En ese caso la compra
	 * esperará detrás de ella y el aviso debe decir «programada».
	 *
	 * @param mixed $queue Cola de `HWE_Memberships_Members::state()`.
	 */
	private static function queueCovers( mixed $queue, int $targetRank ): bool {
		foreach ( is_array( $queue ) ? $queue : array() as $entry ) {
			if ( is_array( $entry ) && (int) ( $entry['rank'] ?? 0 ) >= $targetRank ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Motivo de bloqueo del requisito de acceso, o `null` si el usuario cumple. Un fallo al
	 * evaluarlo bloquea (falla cerrada).
	 *
	 * @return array{reason:string,min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:?string}|null
	 */
	private static function lockReason( int $userId, int $productId ): ?array {
		if ( $productId <= 0 ) {
			return null;
		}

		if ( ! class_exists( 'HWE_Memberships_Access' ) ) {
			self::logOnce( 'access-missing', 'no está disponible la evaluación de requisitos; el producto ' . $productId . ' se trata como bloqueado.' );
			return self::fallbackLock();
		}

		try {
			$lock = HWE_Memberships_Access::lockReason( $userId, $productId );
		} catch ( \Throwable $e ) {
			self::logOnce(
				'access:' . $productId,
				'no se pudo evaluar el requisito del producto ' . $productId . '; se trata como bloqueado.'
			);
			return self::fallbackLock();
		}

		return is_array( $lock ) ? $lock : null;
	}

	/** @return array{reason:string,min_rank:int,level_id:int,level_slug:string,level_name:string,opens_at:?string} */
	private static function fallbackLock(): array {
		return array(
			'reason'     => self::REASON_REQUIRED,
			'min_rank'   => 0,
			'level_id'   => 0,
			'level_slug' => '',
			'level_name' => '',
			'opens_at'   => null,
		);
	}

	/**
	 * Datos del bloqueo con la forma estable que consumen la extensión y los errores.
	 *
	 * @param array<string,mixed> $lock
	 * @return array<string,mixed>
	 */
	private static function lockData( array $lock ): array {
		return array(
			'reason'     => (string) ( $lock['reason'] ?? self::REASON_REQUIRED ),
			'min_rank'   => (int) ( $lock['min_rank'] ?? 0 ),
			'level_slug' => (string) ( $lock['level_slug'] ?? '' ),
			'level_name' => (string) ( $lock['level_name'] ?? '' ),
			'opens_at'   => null === ( $lock['opens_at'] ?? null ) ? null : (string) $lock['opens_at'],
		);
	}

	// ------------------------------------------------------------------------
	//  Carrito, normalización y utilidades
	// ------------------------------------------------------------------------

	/**
	 * Líneas del carrito con la clave, el id comprable (la variación si la hay) y la cantidad.
	 *
	 * @return list<array{key:string,product_id:int,quantity:int}>
	 */
	private static function cartLines( mixed $cart ): array {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return array();
		}

		$lines = array();
		foreach ( (array) $cart->get_cart() as $key => $cartItem ) {
			if ( ! is_array( $cartItem ) ) {
				continue;
			}

			$productId = (int) ( $cartItem['variation_id'] ?? 0 );
			if ( $productId <= 0 ) {
				$productId = (int) ( $cartItem['product_id'] ?? 0 );
			}
			if ( $productId <= 0 ) {
				continue;
			}

			$lines[] = array(
				'key'        => (string) $key,
				'product_id' => $productId,
				'quantity'   => max( 1, (int) ( $cartItem['quantity'] ?? 1 ) ),
			);
		}

		return $lines;
	}

	/** Un producto de membresía, o `null`. Una variación hereda el vínculo de su padre. */
	private static function membershipOf( int $productId ): ?array {
		if ( $productId <= 0 || ! class_exists( 'HWE_Memberships_Products' ) ) {
			return null;
		}

		$membership = HWE_Memberships_Products::membershipOf( $productId );
		if ( null !== $membership ) {
			return $membership;
		}

		$parentId = self::accessProductId( $productId );

		return $parentId !== $productId ? HWE_Memberships_Products::membershipOf( $parentId ) : null;
	}

	/** Producto con el que se evalúa el requisito: la variación usa su producto padre. */
	private static function accessProductId( int $productId ): int {
		if ( $productId <= 0 || ! function_exists( 'wp_get_post_parent_id' ) ) {
			return $productId;
		}

		$parentId = (int) wp_get_post_parent_id( $productId );

		return $parentId > 0 ? $parentId : $productId;
	}

	/**
	 * Normaliza la rama económica del filtro. Importes en unidades menores (string), líneas
	 * indexadas por la clave del carrito.
	 *
	 * @return array{total:string,lines:object}
	 */
	private static function normalizeDiscount( mixed $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$lines = array();

		$rawLines = $raw['lines'] ?? array();
		if ( is_object( $rawLines ) ) {
			$rawLines = (array) $rawLines;
		}
		if ( is_array( $rawLines ) ) {
			foreach ( $rawLines as $key => $value ) {
				$key = is_scalar( $key ) ? trim( (string) $key ) : '';
				if ( '' === $key ) {
					continue;
				}
				$lines[ $key ] = self::minorAmount( $value );
			}
		}

		return array(
			'total' => self::minorAmount( $raw['total'] ?? '0' ),
			'lines' => (object) $lines,
		);
	}

	/**
	 * Normaliza la rama del envío gratis. `requested` se conserva del estado calculado: el
	 * filtro no puede declarar una petición que el cliente no hizo.
	 *
	 * @return array{available:bool,remaining:int,requested:bool,applied:bool,reason:?string}
	 */
	private static function normalizeFreeDelivery( mixed $raw, bool $requested ): array {
		$raw = is_array( $raw ) ? $raw : array();

		$reason = $raw['reason'] ?? null;
		$reason = is_string( $reason ) && '' !== trim( $reason ) ? trim( $reason ) : null;

		return array(
			'available' => ! empty( $raw['available'] ),
			'remaining' => max( 0, (int) ( $raw['remaining'] ?? 0 ) ),
			'requested' => $requested,
			'applied'   => ! empty( $raw['applied'] ),
			'reason'    => $reason,
		);
	}

	/** Importe en unidades menores como string; lo que no sea un entero no negativo vale 0. */
	private static function minorAmount( mixed $value ): string {
		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) ) {
			return '0';
		}
		if ( is_int( $value ) ) {
			return (string) max( 0, $value );
		}
		if ( is_float( $value ) ) {
			return (string) max( 0, (int) round( $value ) );
		}
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return 1 === preg_match( '/^\d+$/', $value ) ? (string) (int) $value : '0';
	}

	/** Error de la Store API con el código, el status y los datos estructurados del bloqueo. */
	private static function error( array $lock, array $extra = array() ): \Throwable {
		$reason  = (string) ( $lock['reason'] ?? self::REASON_REQUIRED );
		$code    = 'hwe_' . $reason;
		$payload = array_merge(
			array(
				'status'   => self::CONFLICT_STATUS,
				'reason'   => $reason,
				'level_id' => (int) ( $lock['level_id'] ?? 0 ),
			),
			self::lockData( $lock ),
			$extra
		);
		$class = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

		if ( class_exists( $class ) ) {
			return new $class( $code, self::message( $reason ), self::CONFLICT_STATUS, $payload );
		}

		self::log( 'la Store API no está disponible; se rechaza la compra con «' . $reason . '».' );

		return new \RuntimeException( self::message( $reason ) );
	}

	/**
	 * Texto de respaldo en español. El BFF no usa estas frases: compone las suyas con
	 * `messages/` a partir del código y de los datos estructurados.
	 */
	private static function message( string $reason ): string {
		switch ( $reason ) {
			case self::REASON_EARLY_ACCESS:
				return __( 'Este producto todavía no está disponible para tu cuenta.', 'hwe' );
			case self::REASON_REQUIRED:
			default:
				return __( 'Este producto necesita una membresía para comprarse.', 'hwe' );
		}
	}

	private static function currentUserId(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/** Carrito de la sesión actual, o `null` fuera de una petición de WooCommerce. */
	private static function cart(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$woocommerce = WC();

		return is_object( $woocommerce ) && isset( $woocommerce->cart ) && is_object( $woocommerce->cart )
			? $woocommerce->cart
			: null;
	}

	/** Sesión de WooCommerce, o `null` fuera de una petición con sesión. */
	private static function session(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$woocommerce = WC();
		if ( ! is_object( $woocommerce ) || ! isset( $woocommerce->session ) || ! is_object( $woocommerce->session ) ) {
			return null;
		}
		if ( ! method_exists( $woocommerce->session, 'get' ) || ! method_exists( $woocommerce->session, 'set' ) ) {
			return null;
		}

		return $woocommerce->session;
	}

	/** El módulo está activo. Sin Control Center manda la constante del plugin cargado. */
	private static function moduleActive(): bool {
		if ( class_exists( 'HWE_Memberships_Settings' ) ) {
			try {
				return HWE_Memberships_Settings::moduleActive();
			} catch ( \Throwable $e ) {
				self::logOnce( 'module-active', 'no se pudo comprobar si el módulo está activo; se usa la constante del plugin.' );
				return defined( 'HWE_MEMBERSHIPS_VERSION' );
			}
		}

		return defined( 'HWE_MEMBERSHIPS_VERSION' );
	}

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}

	/** Registra un aviso una sola vez por petición. */
	private static function logOnce( string $key, string $message ): void {
		if ( isset( self::$logged[ $key ] ) ) {
			return;
		}
		self::$logged[ $key ] = true;
		self::log( $message );
	}
}
