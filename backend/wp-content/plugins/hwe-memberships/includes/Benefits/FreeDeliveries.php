<?php
/**
 * Envíos gratis por periodo (spec §7.3).
 *
 * El nivel configura **cuántos envíos gratis** aporta cada concesión. El cupo es de la
 * concesión, no del nivel: una renovación empieza con su propio contador y el cupo que no se
 * usó en la concesión anterior muere con ella. Los contadores viven en
 * `grants.shipping_quota_used` / `shipping_quota_reserved` y solo los mueve
 * `HWE_Memberships_Grants`, siempre con la fila del socio bloqueada y en la misma transacción
 * que comprueba el disponible.
 *
 * **Ciclo del cupo.** El navegador solo declara en el carrito que quiere usar un envío gratis
 * (`HWE_Memberships_StoreApi`, extensión §10.4). WordPress decide si puede:
 *  · al calcular el carrito, si el beneficio está activo y queda cupo, la tarifa elegible queda a
 *    0 y se marca con un motivo estable;
 *  · al crear el pedido se **reserva** una unidad; si ya no queda, el checkout se corta con
 *    `hwe_membership_free_delivery_unavailable` (409) y el pedido no se cobra;
 *  · al cobrarse el pedido se **consume**; si se cancela o falla antes de cobrarse, o se
 *    reembolsa del todo, se **devuelve**. Todo es idempotente por pedido: las notificaciones de
 *    estado repetidas no consumen dos veces.
 *
 * **Reintentos.** WooCommerce reutiliza el mismo pedido cuando un intento del checkout falla, y
 * al reutilizarlo rehace líneas y tarifas pero no las metas del pedido. Por eso la meta del
 * envío gratis refleja siempre lo que el pedido lleva: si el beneficio ya no se aplica a este
 * intento, la reserva viva se devuelve; si el pedido se cobra sin la marca del beneficio, su
 * reserva también se devuelve; y un pedido devuelto que aún no se ha cobrado (fallido o
 * cancelado) puede volver a reservar cuando el cliente reintenta.
 *
 * **Integración con `shipping`.** Si el módulo `shipping` está activo, esta clase NO toca las
 * tarifas: pide el envío gratis por su filtro estable `hwe_shipping_free_reason` y deja que él
 * aplique su propia lógica (solo opciones elegibles, nunca la reserva) y escriba su meta
 * `_hwe_free_shipping_reason`. Sin `shipping`, pone a 0 las tarifas de Woo que no son de
 * recogida, identificadas por el **id del método** (`local_pickup`, `pickup_location`), nunca
 * por el texto de la etiqueta. Si el envío ya salía a 0 por otra regla, no se reserva cupo: el
 * estado público lo dice con `reason = already_free`.
 *
 * La extensión del carrito publica `free_delivery` = `{available, remaining, requested, applied,
 * reason}`; los textos que ve el cliente los compone el BFF a partir de esos datos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Benefit_Free_Deliveries extends HWE_Memberships_Benefit {

	/** Clave estable del tipo. */
	public const KEY = 'free_deliveries';

	/** Motivo estable con el que este módulo pide el envío gratis a `shipping`. */
	public const FREE_REASON = 'membership_free_delivery';

	/** Filtro estable que publica `hwe-shipping` («Hooks estables» de su README). */
	public const SHIPPING_FILTER = 'hwe_shipping_free_reason';

	/** Constante con la que `hwe-shipping` anuncia que admite ese filtro. */
	public const SHIPPING_SUPPORT = 'HWE_SHIPPING_FREE_REASON_FILTER';

	/** Meta de la línea de envío donde `shipping` escribe el motivo. Contrato estable suyo. */
	public const SHIPPING_REASON_META = '_hwe_free_shipping_reason';

	/** Marca que esta clase deja en la tarifa cuando no hay módulo `shipping`. */
	public const RATE_META = '_hwe_membership_free_delivery';

	/** Motivos estables del estado público (los textos los compone el BFF). */
	public const REASON_ALREADY_FREE = 'already_free';
	public const REASON_QUOTA        = 'quota_exhausted';
	public const REASON_NOT_ELIGIBLE = 'not_eligible';
	public const REASON_UNAVAILABLE  = 'not_available';

	/** Motivo y estado HTTP del checkout cuando no queda cupo. */
	public const ERROR_REASON = 'membership_free_delivery_unavailable';
	public const ERROR_CODE   = 'hwe_' . self::ERROR_REASON;
	public const ERROR_STATUS = 409;

	/** Ids de método de WooCommerce que son recogida y nunca cuentan como envío. */
	public const PICKUP_METHODS = array( 'local_pickup', 'pickup_location' );

	/** Claves internas del resultado del cálculo de tarifas de la petición. */
	private const OUTCOME_APPLIED      = 'applied';
	private const OUTCOME_ALREADY_FREE = 'already_free';
	private const OUTCOME_NOT_ELIGIBLE = 'not_eligible';
	private const OUTCOME_QUOTA        = 'quota';
	private const OUTCOME_UNAVAILABLE  = 'unavailable';

	/** Resultado de la última aplicación de tarifas de esta petición, o `null` si no hubo. */
	private static ?string $rateOutcome = null;

	/** Avisos ya emitidos en esta petición. */
	private static array $logged = array();

	public function key(): string {
		return self::KEY;
	}

	/** Funciona sin `shipping`: con él usa su filtro; sin él, las tarifas de Woo. */
	public function dependsOn(): ?string {
		return null;
	}

	public function fields(): array {
		return array(
			'count' => array(
				'type'    => self::FIELD_NUMBER,
				'label'   => function_exists( '__' ) ? __( 'Envíos gratis por periodo', 'hwe' ) : 'Envíos gratis por periodo',
				'default' => 0,
				'min'     => 0,
				'max'     => 3650,
			),
		);
	}

	public function publicData( int $levelId ): array {
		$config = $this->configFor( $levelId );

		return array(
			'key'   => $this->key(),
			'count' => null === $config ? 0 : max( 0, (int) ( $config['count'] ?? 0 ) ),
		);
	}

	/**
	 * Estado del cupo del socio para el nivel efectivo. Nunca escribe: la caducidad se calcula
	 * por fechas y las reservas se leen de la concesión.
	 *
	 * @return array{grant_id:int,count:int,used:int,reserved:int,remaining:int,available:bool}
	 */
	public function quotaFor( int $userId ): array {
		$empty = array(
			'grant_id'  => 0,
			'count'     => 0,
			'used'      => 0,
			'reserved'  => 0,
			'remaining' => 0,
			'available' => false,
		);

		if (
			$userId <= 0
			|| ! class_exists( 'HWE_Memberships_Settings' )
			|| ! class_exists( 'HWE_Memberships_Members' )
			|| ! class_exists( 'HWE_Memberships_Grants' )
		) {
			return $empty;
		}

		try {
			$active = HWE_Memberships_Settings::moduleActive();
		} catch ( \Throwable $e ) {
			self::logOnce( 'quota:settings', 'no se pudo comprobar si el módulo está activo; el cupo se trata como no disponible.' );
			return $empty;
		}
		if ( ! $active ) {
			return $empty;
		}

		try {
			$state = HWE_Memberships_Members::state( $userId );
		} catch ( \Throwable $e ) {
			self::logOnce( 'quota:state:' . $userId, 'no se pudo leer el estado del socio ' . $userId . '; el cupo se trata como no disponible.' );
			return $empty;
		}
		if ( HWE_Memberships_Members::STATUS_ACTIVE !== (string) ( $state['status'] ?? '' ) ) {
			return $empty;
		}

		$levelId = (int) ( $state['level_id'] ?? 0 );
		try {
			$config = $levelId > 0 ? $this->configFor( $levelId ) : null;
		} catch ( \Throwable $e ) {
			self::logOnce( 'quota:config:' . $levelId, 'no se pudo leer la configuración del nivel ' . $levelId . '; el cupo se trata como no disponible.' );
			return $empty;
		}
		$count   = null === $config ? 0 : max( 0, (int) ( $config['count'] ?? 0 ) );
		if ( null === $config || empty( $config['enabled'] ) ) {
			return array_merge( $empty, array( 'count' => $count ) );
		}

		$grant = $this->grantFor( $userId, (string) ( $state['grant_key'] ?? '' ) );
		if ( null === $grant ) {
			self::logOnce( 'grant:' . $userId, 'el socio ' . $userId . ' no tiene una concesión legible para el envío gratis.' );
			return array_merge( $empty, array( 'count' => $count ) );
		}

		$used     = max( 0, (int) ( $grant['shipping_quota_used'] ?? 0 ) );
		$reserved = max( 0, (int) ( $grant['shipping_quota_reserved'] ?? 0 ) );
		$left     = max( 0, $count - $used - $reserved );

		return array(
			'grant_id'  => (int) ( $grant['id'] ?? 0 ),
			'count'     => $count,
			'used'      => $used,
			'reserved'  => $reserved,
			'remaining' => $left,
			'available' => $count > 0 && $left > 0,
		);
	}

	public function stateFor( int $userId ): array {
		$state = parent::stateFor( $userId );
		$quota = $this->quotaFor( $userId );

		$state['count']     = $quota['count'];
		$state['used']      = $quota['used'];
		$state['reserved']  = $quota['reserved'];
		$state['remaining'] = $quota['remaining'];

		return $state;
	}

	// ------------------------------------------------------------------------
	//  Hooks
	// ------------------------------------------------------------------------

	protected function registerHooks(): void {
		$cartFilter = class_exists( 'HWE_Memberships_StoreApi' )
			? HWE_Memberships_StoreApi::CART_DATA_FILTER
			: 'hwe_memberships_store_api_cart_data';
		add_filter( $cartFilter, array( $this, 'cartData' ), 10, 2 );

		add_filter( 'hwe_memberships_order_box_rows', array( $this, 'orderBoxRows' ), 10, 2 );

		// WooCommerce guarda en la sesión las tarifas ya filtradas, con un hash del paquete como
		// llave. Que el socio pida el envío gratis, o que aún quede cupo, no está en ese hash:
		// hay que meterlo en el paquete para que el cambio recalcule las tarifas.
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'markPackages' ), 20, 1 );

		// La reserva va después de las validaciones del checkout (Tareas 6 y 11) y antes del cobro.
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'reserveForOrder' ), 20, 2 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'onOrderCollected' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'onOrderCollected' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'onOrderCollected' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'onOrderCancelled' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'onOrderFailed' ), 20, 1 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'onOrderDeleted' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'onOrderRefunded' ), 20, 1 );

		if ( self::shippingModuleActive() ) {
			if ( self::shippingSupportsFreeReason() ) {
				add_filter( self::SHIPPING_FILTER, array( $this, 'shippingFreeReason' ), 10, 3 );
			} else {
				self::logOnce(
					'shipping-support',
					'el módulo shipping está activo pero no publica ' . self::SHIPPING_FILTER . '; el envío gratis de miembro no se aplica.'
				);
			}
			return;
		}

		// Sin módulo shipping: el beneficio pone a 0 las tarifas que no son de recogida. Va después
		// del envío gratis por cupón (30), así un envío que ya salía a 0 no se atribuye al cupo.
		add_filter( 'woocommerce_package_rates', array( $this, 'applyToRates' ), 35, 2 );
	}

	// ------------------------------------------------------------------------
	//  Caché de tarifas de WooCommerce
	// ------------------------------------------------------------------------

	/** Clave que se añade a cada paquete de envío del carrito. */
	public const PACKAGE_KEY = 'hwe_free_delivery';

	/**
	 * `woocommerce_cart_shipping_packages`: marca cada paquete con «el socio pidió el envío gratis
	 * y le queda cupo». WooCommerce calcula el hash con que reutiliza las tarifas de la sesión a
	 * partir del paquete; sin esta marca, pedir o quitar el envío gratis devolvía las tarifas de
	 * antes (pedirlo no lo aplicaba y quitarlo lo dejaba aplicado, con el pedido sin cupo
	 * reservado). Sin petición no se consulta el cupo.
	 *
	 * @param mixed $packages
	 * @return mixed
	 */
	public function markPackages( mixed $packages ): mixed {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		$wanted = $this->requested() && $this->quotaFor( self::currentUserId() )['available'];

		foreach ( $packages as $index => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $index ][ self::PACKAGE_KEY ] = $wanted ? 'requested' : 'no';
			}
		}

		return $packages;
	}

	// ------------------------------------------------------------------------
	//  Extensión de la Store API
	// ------------------------------------------------------------------------

	/**
	 * Rellena `free_delivery` con el cupo real y si el beneficio se aplicaría a este carrito.
	 * `requested` se conserva del estado calculado por `HWE_Memberships_StoreApi`.
	 *
	 * @param mixed $data    Estado base del carrito.
	 * @param mixed $context Contexto del filtro (`cart`, `user_id`, `state`).
	 * @return array<string,mixed>
	 */
	public function cartData( mixed $data, mixed $context = array() ): array {
		if ( ! is_array( $data ) ) {
			self::logOnce( 'cart-data', 'el filtro de la extensión recibió ' . get_debug_type( $data ) . '; se ignora.' );
			return array();
		}

		$context   = is_array( $context ) ? $context : array();
		$userId    = (int) ( $context['user_id'] ?? 0 );
		$requested = ! empty( $data['free_delivery']['requested'] );
		$quota     = $this->quotaFor( $userId );

		$applied = false;
		$reason  = null;

		$cart          = $context['cart'] ?? null;
		$needsShipping = ! is_object( $cart )
			|| ! method_exists( $cart, 'needs_shipping' )
			|| (bool) $cart->needs_shipping();

		if ( $requested && ! $needsShipping ) {
			$reason = self::REASON_NOT_ELIGIBLE;
		} elseif ( $requested && $quota['available'] && self::shippingModuleActive() && self::cartHasFreeShippingCoupon( $cart ) ) {
			// Con shipping, su cupón de envío gratis ya deja a 0 todas las tarifas: el cupo no se gasta.
			$reason = self::REASON_ALREADY_FREE;
		} elseif ( $requested && $quota['available'] ) {
			$outcome = self::$rateOutcome;
			if ( null === $outcome ) {
				$outcome = self::outcomeFromRates();
			}
			if ( null === $outcome ) {
				// Las tarifas de este carrito aún no se calcularon: se anticipa lo que hará el
				// beneficio; el siguiente recálculo lo confirma.
				$outcome = $this->wouldApply() ? self::OUTCOME_APPLIED : self::OUTCOME_NOT_ELIGIBLE;
			}

			switch ( $outcome ) {
				case self::OUTCOME_APPLIED:
					$applied = true;
					$reason  = self::FREE_REASON;
					break;
				case self::OUTCOME_ALREADY_FREE:
					$reason = self::REASON_ALREADY_FREE;
					break;
				case self::OUTCOME_QUOTA:
					$reason = self::REASON_QUOTA;
					break;
				case self::OUTCOME_UNAVAILABLE:
					$reason = self::REASON_UNAVAILABLE;
					break;
				default:
					$reason = self::REASON_NOT_ELIGIBLE;
			}
		} elseif ( $requested ) {
			$reason = $quota['count'] > 0 ? self::REASON_QUOTA : self::REASON_UNAVAILABLE;
		}

		$data['free_delivery'] = array(
			'available' => $quota['available'],
			'remaining' => $quota['remaining'],
			'requested' => $requested,
			'applied'   => $applied,
			'reason'    => $reason,
		);

		return $data;
	}

	// ------------------------------------------------------------------------
	//  shipping: filtro estable
	// ------------------------------------------------------------------------

	/**
	 * Pide el envío gratis a `hwe-shipping` cuando el cliente lo pidió, el beneficio está activo,
	 * queda cupo y hay alguna opción elegible en el destino. No aplica ninguna tarifa: eso es de
	 * `shipping`.
	 *
	 * @param mixed $reason  Motivo ya decidido por otro módulo (se respeta).
	 * @param mixed $package Paquete de WooCommerce.
	 * @param mixed $context Contexto de `shipping` (`rate_ids`, `city`, `qualifies`).
	 * @return mixed
	 */
	public function shippingFreeReason( mixed $reason, mixed $package = array(), mixed $context = array() ): mixed {
		if ( null !== $reason && '' !== trim( (string) ( is_scalar( $reason ) ? $reason : '' ) ) ) {
			return $reason;
		}
		if ( ! is_array( $package ) ) {
			return $reason;
		}

		if ( ! $this->requested() ) {
			$this->recordOutcome( self::OUTCOME_NOT_ELIGIBLE );
			return $reason;
		}

		$quota = $this->quotaFor( self::currentUserId() );
		if ( ! $quota['available'] ) {
			$this->recordOutcome( $quota['count'] > 0 ? self::OUTCOME_QUOTA : self::OUTCOME_UNAVAILABLE );
			return $reason;
		}

		$context = is_array( $context ) ? $context : array();
		$rates   = isset( $context['rate_ids'] ) && is_array( $context['rate_ids'] ) ? $context['rate_ids'] : array();
		if ( array() === $rates ) {
			$this->recordOutcome( self::OUTCOME_NOT_ELIGIBLE );
			return $reason;
		}

		$this->recordOutcome( self::OUTCOME_APPLIED );

		return self::FREE_REASON;
	}

	// ------------------------------------------------------------------------
	//  Sin shipping: tarifas de WooCommerce
	// ------------------------------------------------------------------------

	/**
	 * Pone a 0 las tarifas que no son de recogida, por el id del método. Marca la tarifa para que
	 * la línea del pedido permita reservar el cupo al crearlo.
	 *
	 * @param mixed $rates
	 * @param mixed $package
	 * @return mixed
	 */
	public function applyToRates( mixed $rates, mixed $package = array() ): mixed {
		if ( ! is_array( $rates ) ) {
			return $rates;
		}
		// Con `shipping` activo sus tarifas las decide su filtro, nunca este hook.
		if ( self::shippingModuleActive() ) {
			return $rates;
		}

		if ( ! $this->requested() ) {
			$this->recordOutcome( self::OUTCOME_NOT_ELIGIBLE );
			return $rates;
		}

		$quota = $this->quotaFor( self::currentUserId() );
		if ( ! $quota['available'] ) {
			$this->recordOutcome( $quota['count'] > 0 ? self::OUTCOME_QUOTA : self::OUTCOME_UNAVAILABLE );
			return $rates;
		}

		$applied  = false;
		$eligible = 0;
		$free     = 0;

		foreach ( $rates as $rate ) {
			if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_method_id' ) ) {
				continue;
			}
			if ( $this->isPickup( (string) $rate->get_method_id() ) ) {
				continue;
			}

			$eligible++;

			// Una segunda pasada sobre las mismas tarifas no debe perder la marca.
			$meta = method_exists( $rate, 'get_meta_data' ) ? (array) $rate->get_meta_data() : array();
			if ( ! empty( $meta[ self::RATE_META ] ) ) {
				$applied = true;
				continue;
			}

			$cost = method_exists( $rate, 'get_cost' ) ? (float) $rate->get_cost() : 0.0;
			if ( $cost <= 0.0 ) {
				$free++;
				continue;
			}

			$rate->set_cost( 0 );
			$rate->set_taxes( array() );
			$rate->add_meta_data( self::RATE_META, self::FREE_REASON );
			$applied = true;
		}

		if ( $applied ) {
			$this->recordOutcome( self::OUTCOME_APPLIED );
		} elseif ( $eligible > 0 && $free === $eligible ) {
			$this->recordOutcome( self::OUTCOME_ALREADY_FREE );
		} else {
			$this->recordOutcome( self::OUTCOME_NOT_ELIGIBLE );
		}

		return $rates;
	}

	// ------------------------------------------------------------------------
	//  Pedido: reserva, consumo y devolución
	// ------------------------------------------------------------------------

	/**
	 * Reserva una unidad al crear el pedido de la Store API. Solo lo hace si el cliente lo pidió y
	 * el pedido lleva la marca del beneficio (la tarifa quedó a 0 por este motivo). Si ya no queda
	 * cupo, corta el checkout con 409 y el pedido no se cobra.
	 *
	 * Si el pedido viene de un intento anterior (WooCommerce reutiliza el borrador) y el beneficio
	 * ya no se aplica a este, la reserva viva se devuelve: la meta del pedido no puede seguir
	 * diciendo que el envío gratis está reservado cuando el pedido lo cobra.
	 *
	 * Devuelve `true` solo si el pedido queda con una reserva (nueva o ya existente); `false` si
	 * el beneficio no se aplicó a este pedido.
	 *
	 * @param mixed $order
	 * @param mixed $request
	 * @throws \Throwable Con el código `hwe_membership_free_delivery_unavailable`.
	 */
	public function reserveForOrder( mixed $order, mixed $request = null ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return false;
		}
		if ( ! $this->requested() ) {
			$this->releaseStaleReservation( $order );
			return false;
		}
		if ( ! $this->orderApplied( $order ) ) {
			// No se aplicó: el destino no es elegible o el envío ya salía a 0 por otra regla.
			$this->releaseStaleReservation( $order );
			return false;
		}
		if ( self::shippingModuleActive() && self::orderHasFreeShippingCoupon( $order ) ) {
			// Con shipping, el cupón de envío gratis ya pagaba el envío: no se gasta el cupo.
			$this->releaseStaleReservation( $order );
			return false;
		}

		$orderId = (int) $order->get_id();
		if ( $orderId <= 0 ) {
			return false;
		}
		if ( ! $this->reserve( $orderId ) ) {
			throw self::error();
		}

		return true;
	}

	/**
	 * Reserva una unidad del cupo para un pedido ya creado (el usuario sale del pedido, nunca del
	 * navegador). Idempotente por pedido.
	 */
	public function reserve( int $orderId ): bool {
		$order = self::order( $orderId );
		if ( null === $order || ! method_exists( $order, 'get_customer_id' ) ) {
			return false;
		}
		if ( ! class_exists( 'HWE_Memberships_Grants' ) ) {
			return false;
		}

		return HWE_Memberships_Grants::reserveFreeDelivery( (int) $order->get_customer_id(), $orderId );
	}

	/** Consume la reserva de un pedido cobrado. Idempotente por pedido. */
	public function consume( int $orderId ): bool {
		return class_exists( 'HWE_Memberships_Grants' )
			&& HWE_Memberships_Grants::consumeFreeDelivery( $orderId );
	}

	/** Devuelve la unidad de un pedido cancelado, fallido o reembolsado del todo. Idempotente. */
	public function release( int $orderId ): bool {
		return class_exists( 'HWE_Memberships_Grants' )
			&& HWE_Memberships_Grants::releaseFreeDelivery( $orderId );
	}

	/**
	 * Devuelve la reserva viva de un pedido cuando el beneficio ya no se aplica a este intento.
	 * Solo toca el estado `reserved`: un pedido cobrado conserva la unidad que gastó.
	 */
	private function releaseStaleReservation( object $order ): void {
		if ( ! class_exists( 'HWE_Memberships_Grants' ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		if ( HWE_Memberships_Grants::FREE_DELIVERY_RESERVED !== trim( (string) $order->get_meta( HWE_Memberships_Grants::META_FREE_DELIVERY_STATE ) ) ) {
			return;
		}

		$orderId = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( $orderId <= 0 ) {
			return;
		}

		HWE_Memberships_Grants::releaseFreeDelivery( $orderId, true );
	}

	/** Cobrado: consume la reserva (una sola vez por pedido). */
	public function onOrderCollected( int $orderId ): void {
		$order = self::order( $orderId );
		if ( null === $order ) {
			self::logOnce( 'order:collected:' . $orderId, 'no se pudo cargar el pedido ' . $orderId . ' para consumir su envío gratis.' );
			return;
		}
		if ( class_exists( 'HWE_Memberships_Purchase' ) && ! HWE_Memberships_Purchase::isCollected( $order ) ) {
			return;
		}
		if ( ! $this->orderApplied( $order ) ) {
			// El pedido se cobró sin el envío gratis del beneficio (la línea se rehízo con coste
			// en un reintento del borrador): la reserva viva ya no corresponde y se devuelve.
			$this->releaseStaleReservation( $order );
			return;
		}

		$this->consume( $orderId );
	}

	/** Cancelado antes de cobrarse: devuelve la reserva. */
	public function onOrderCancelled( int $orderId ): void {
		$this->release( $orderId );
	}

	/** Fallido antes de cobrarse: devuelve la reserva. */
	public function onOrderFailed( int $orderId ): void {
		$this->release( $orderId );
	}

	/**
	 * Borrado de un pedido con la unidad aún reservada. WooCommerce elimina a las 24 h los
	 * borradores de la Store API que el cliente abandonó (por ejemplo tras un error del checkout)
	 * sin pasar por `cancelled` ni `failed`: sin esto la reserva quedaría viva hasta que muriera
	 * la concesión. Un pedido cobrado ya gastó su envío y conserva su unidad.
	 */
	public function onOrderDeleted( int $orderId ): void {
		$order = self::order( $orderId );
		if ( null === $order || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}

		$state = trim( (string) $order->get_meta( HWE_Memberships_Grants::META_FREE_DELIVERY_STATE ) );
		if ( HWE_Memberships_Grants::FREE_DELIVERY_RESERVED !== $state ) {
			return;
		}

		$this->release( $orderId );
	}

	/** Solo un reembolso TOTAL devuelve lo consumido. */
	public function onOrderRefunded( int $orderId ): void {
		$order = self::order( $orderId );
		if ( null === $order ) {
			self::logOnce( 'order:refunded:' . $orderId, 'no se pudo cargar el pedido ' . $orderId . ' para devolver su envío gratis.' );
			return;
		}

		$refunded = method_exists( $order, 'get_total_refunded' ) ? (float) $order->get_total_refunded() : 0.0;
		if ( $refunded > 0 && $refunded + 0.01 < (float) $order->get_total() ) {
			return;
		}

		$this->release( $orderId );
	}

	// ------------------------------------------------------------------------
	//  Caja del pedido
	// ------------------------------------------------------------------------

	/**
	 * Una sola fila con la concesión y el estado del envío gratis; retira las filas genéricas que
	 * la caja genera para las metas del módulo.
	 *
	 * @param mixed $rows
	 * @param mixed $order
	 * @return mixed
	 */
	public function orderBoxRows( mixed $rows, mixed $order = null ): mixed {
		if ( ! is_array( $rows ) || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return $rows;
		}

		$grantId = (int) $order->get_meta( HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT );
		if ( $grantId <= 0 ) {
			return $rows;
		}

		$state = trim( (string) $order->get_meta( HWE_Memberships_Grants::META_FREE_DELIVERY_STATE ) );
		$skip  = array(
			self::metaLabel( HWE_Memberships_Grants::META_FREE_DELIVERY_GRANT ),
			self::metaLabel( HWE_Memberships_Grants::META_FREE_DELIVERY_STATE ),
			self::metaLabel( HWE_Memberships_Grants::META_FREE_DELIVERY_USER ),
		);

		$filtered = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$filtered[] = $row;
				continue;
			}
			$label = isset( $row['label'] ) && is_scalar( $row['label'] ) ? trim( (string) $row['label'] ) : '';
			if ( in_array( $label, $skip, true ) ) {
				continue;
			}
			$filtered[] = $row;
		}

		$filtered[] = array(
			'label' => __( 'Envío gratis', 'hwe' ),
			'value' => sprintf(
				/* translators: 1: id de la concesión, 2: estado del envío gratis. */
				__( 'concesión #%1$d · %2$s', 'hwe' ),
				$grantId,
				self::stateLabel( $state )
			),
			'state' => '' !== $state ? $state : 'info',
		);

		return $filtered;
	}

	// ------------------------------------------------------------------------
	//  Estado interno por petición
	// ------------------------------------------------------------------------

	/** Reinicia el resultado del cálculo de tarifas (pruebas y peticiones largas). */
	public static function flushRateState(): void {
		self::$rateOutcome = null;
	}

	/**
	 * ¿El carrito lleva un cupón de envío gratis de WooCommerce? Es un dato del núcleo de
	 * WooCommerce (`WC_Coupon::get_free_shipping`), no de otro módulo.
	 */
	private static function cartHasFreeShippingCoupon( mixed $cart ): bool {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_applied_coupons' ) ) {
			return false;
		}

		return self::hasFreeShippingCoupon( (array) $cart->get_applied_coupons() );
	}

	/** ¿El pedido usó un cupón de envío gratis? */
	private static function orderHasFreeShippingCoupon( object $order ): bool {
		if ( ! method_exists( $order, 'get_coupon_codes' ) ) {
			return false;
		}

		return self::hasFreeShippingCoupon( (array) $order->get_coupon_codes() );
	}

	/** @param list<mixed> $codes */
	private static function hasFreeShippingCoupon( array $codes ): bool {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return false;
		}

		foreach ( $codes as $code ) {
			if ( ! is_scalar( $code ) || '' === trim( (string) $code ) ) {
				continue;
			}
			try {
				$coupon = new \WC_Coupon( (string) $code );
			} catch ( \Throwable $e ) {
				self::logOnce( 'coupon:' . (string) $code, 'no se pudo leer el cupón ' . (string) $code . '; se ignora para el envío gratis.' );
				continue;
			}
			if ( method_exists( $coupon, 'get_id' ) && ! $coupon->get_id() ) {
				continue;
			}
			if ( method_exists( $coupon, 'get_free_shipping' ) && $coupon->get_free_shipping() ) {
				return true;
			}
		}

		return false;
	}

	/** ¿Hay que anticipar que el beneficio se aplicará cuando aún no se calcularon las tarifas? */
	private function wouldApply(): bool {
		return ! self::shippingModuleActive() || self::shippingSupportsFreeReason();
	}

	/** El módulo `shipping` existe y está activo (decisión del Control Center, no del archivo). */
	private static function shippingModuleActive(): bool {
		return self::moduleActive( 'shipping' );
	}

	/** `shipping` anuncia el filtro con una constante; así una versión vieja no se rompe. */
	private static function shippingSupportsFreeReason(): bool {
		if ( ! defined( self::SHIPPING_SUPPORT ) ) {
			return false;
		}

		$filter = constant( self::SHIPPING_SUPPORT );

		return is_string( $filter ) && self::SHIPPING_FILTER === $filter;
	}

	/**
	 * Estado del beneficio a partir de las tarifas ya calculadas del carrito (WooCommerce las
	 * calcula antes de las extensiones en la Store API). `null` si todavía no hay tarifas.
	 */
	private static function outcomeFromRates(): ?string {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$woocommerce = WC();
		$shipping    = is_object( $woocommerce ) && isset( $woocommerce->shipping ) && is_object( $woocommerce->shipping )
			? $woocommerce->shipping
			: null;
		if ( null === $shipping || ! method_exists( $shipping, 'get_packages' ) ) {
			return null;
		}

		$packages = $shipping->get_packages();
		if ( ! is_array( $packages ) || array() === $packages ) {
			return null;
		}

		$seen    = false;
		$already = false;
		foreach ( $packages as $package ) {
			$rates = is_array( $package ) && isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array();
			foreach ( $rates as $rate ) {
				if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_meta_data' ) ) {
					continue;
				}
				$seen = true;
				$meta = (array) $rate->get_meta_data();

				if ( self::FREE_REASON === trim( (string) ( $meta[ self::SHIPPING_REASON_META ] ?? '' ) ) ) {
					return self::OUTCOME_APPLIED;
				}
				if ( '' !== trim( (string) ( $meta[ self::RATE_META ] ?? '' ) ) ) {
					return self::OUTCOME_APPLIED;
				}
				if ( '' !== trim( (string) ( $meta[ self::SHIPPING_REASON_META ] ?? '' ) ) ) {
					$already = true;
				}
				if ( method_exists( $rate, 'get_cost' ) && (float) $rate->get_cost() <= 0.0 ) {
					$already = true;
				}
			}
		}

		if ( ! $seen ) {
			return null;
		}

		return $already ? self::OUTCOME_ALREADY_FREE : self::OUTCOME_NOT_ELIGIBLE;
	}

	/** ¿El cliente pidió usar un envío gratis en este carrito? */
	private function requested(): bool {
		if ( ! class_exists( 'HWE_Memberships_StoreApi' ) ) {
			return false;
		}
		try {
			return HWE_Memberships_StoreApi::requestedFreeDelivery();
		} catch ( \Throwable $e ) {
			self::logOnce( 'requested', 'no se pudo leer la petición de envío gratis; se trata como no pedida.' );
			return false;
		}
	}

	/** Concesión viva que da el nivel efectivo, con sus contadores de envío. */
	private function grantFor( int $userId, string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}

		try {
			$grants = HWE_Memberships_Grants::forUser( $userId );
		} catch ( \Throwable $e ) {
			self::logOnce( 'quota:grants:' . $userId, 'no se pudieron leer las concesiones del socio ' . $userId . '.' );
			return null;
		}

		foreach ( $grants as $grant ) {
			if ( (string) ( $grant['key'] ?? '' ) === $key ) {
				return $grant;
			}
		}

		return null;
	}

	/**
	 * ¿La línea de envío del pedido lleva la marca de este beneficio? Sin `shipping`, la deja la
	 * propia tarifa (`RATE_META`); con `shipping`, el motivo estable de su meta.
	 */
	private function orderApplied( object $order ): bool {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return false;
		}

		foreach ( (array) $order->get_items( 'shipping' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}
			if ( '' !== trim( (string) $item->get_meta( self::RATE_META ) ) ) {
				return true;
			}
			if ( self::FREE_REASON === trim( (string) $item->get_meta( self::SHIPPING_REASON_META ) ) ) {
				return true;
			}
		}

		return false;
	}

	/** ¿El id del método es de recogida? Se compara el id, nunca la etiqueta visible. */
	private function isPickup( string $methodId ): bool {
		$methodId = strtolower( trim( $methodId ) );
		if ( str_contains( $methodId, ':' ) ) {
			$methodId = (string) strtok( $methodId, ':' );
		}

		return in_array( $methodId, self::PICKUP_METHODS, true );
	}

	/** Motivo estable del estado del pedido para la caja. */
	private static function stateLabel( string $state ): string {
		return match ( $state ) {
			HWE_Memberships_Grants::FREE_DELIVERY_RESERVED => __( 'Reservado', 'hwe' ),
			HWE_Memberships_Grants::FREE_DELIVERY_CONSUMED => __( 'Usado', 'hwe' ),
			HWE_Memberships_Grants::FREE_DELIVERY_RELEASED => __( 'Devuelto', 'hwe' ),
			default                                         => __( 'sin estado', 'hwe' ),
		};
	}

	/** Misma etiqueta que usa la caja para las metas genéricas (`Admin/OrderBox.php`). */
	private static function metaLabel( string $key ): string {
		$key = (string) preg_replace( '/^_hwe_membership_/', '', $key );
		$key = str_replace( array( '_', '-' ), ' ', $key );

		return ucfirst( $key );
	}

	/** Conserva el mejor resultado de la petición si hay varios paquetes. */
	private function recordOutcome( string $outcome ): void {
		$rank = array(
			self::OUTCOME_NOT_ELIGIBLE => 0,
			self::OUTCOME_UNAVAILABLE  => 1,
			self::OUTCOME_QUOTA        => 2,
			self::OUTCOME_ALREADY_FREE => 3,
			self::OUTCOME_APPLIED      => 4,
		);

		$current = null === self::$rateOutcome ? -1 : ( $rank[ self::$rateOutcome ] ?? -1 );
		if ( ( $rank[ $outcome ] ?? -1 ) > $current ) {
			self::$rateOutcome = $outcome;
		}
	}

	private static function currentUserId(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/** @return object|null */
	private static function order( int $orderId ): ?object {
		if ( $orderId <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $orderId );

		return is_object( $order ) ? $order : null;
	}

	/** Error de la Store API con el código y el estado estables; el BFF compone el texto final. */
	private static function error(): \Throwable {
		$message = function_exists( '__' )
			? __( 'No queda ningún envío gratis disponible en tu membresía.', 'hwe' )
			: 'No queda ningún envío gratis disponible en tu membresía.';
		$class   = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

		if ( class_exists( $class ) ) {
			return new $class(
				self::ERROR_CODE,
				$message,
				self::ERROR_STATUS,
				array(
					'status' => self::ERROR_STATUS,
					'reason' => self::ERROR_REASON,
				)
			);
		}

		self::log( 'la Store API no está disponible; se rechaza el checkout por ' . self::ERROR_REASON . '.' );

		return new \RuntimeException( $message );
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
