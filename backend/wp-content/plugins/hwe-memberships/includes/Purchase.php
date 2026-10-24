<?php
/**
 * Compra y activación de membresías al cobrar (spec §5.3).
 *
 * **Reglas de compra.** Se comprueban en la Store API al añadir al carrito y otra vez al crear el
 * pedido (y en el checkout clásico como respaldo). Exigen sesión aunque la tienda permita comprar
 * como invitado, una sola línea de membresía por carrito con cantidad 1, un nivel comprable, la
 * antigüedad mínima del nivel, una cuenta no pendiente (`hwe_account_is_pending`) y que el usuario
 * no tenga ya ese nivel —o uno superior— de forma indefinida. Cada rechazo lleva un motivo estable
 * (`membership_*`) y datos estructurados; el texto que ve el cliente se compone en el BFF. El
 * `RouteException` usa el código `hwe_<motivo>`.
 *
 * **Activación.** Igual que las ganancias de `loyalty`: solo cuando el pedido está cobrado.
 * `processing`/`completed` con `is_paid()`, o `payment_complete`; los medios que cobran al entregar
 * (por defecto `cod`) esperan a `completed`, con el filtro
 * `hwe_memberships_collected_on_completion`. Cada línea deja una concesión con la clave
 * `order:<pedido>:item:<línea>` y guarda su id en la propia línea. Esa clave y la meta del pedido
 * hacen que `processing` → `completed` → `payment_complete`, un webhook repetido o una ida y
 * vuelta de estado no concedan dos veces (Foco de revisión 1).
 *
 * **Reversión.** `cancelled` o `refunded` **total** revoca las concesiones de las líneas con
 * `Grants::revokeByKey()`; un reembolso parcial no revierte (decisión alineada con `loyalty`). Un
 * pedido de invitado no activa nada: se registra y se ignora.
 *
 * **Congelación.** Comprar estando congelado se permite: la concesión se coloca en la línea de
 * tiempo y se desplaza al descongelar como cualquier otra (decisión propia, documentada).
 *
 * WooCommerce guarda las metas con su API (`$order->update_meta_data()`/`save_meta_data()` y
 * `$item->update_meta_data()`/`save()`), compatible con HPOS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Purchase {

	/** Id de la concesión creada por la línea del pedido. */
	public const META_LINE_GRANT = '_hwe_membership_grant_id';

	/** Nivel comprado, guardado en la línea como foto del momento de la compra. */
	public const META_LINE_LEVEL = '_hwe_membership_level_id';

	/** Duración comprada en días (`0` = indefinida), guardada en la línea. */
	public const META_LINE_DAYS = '_hwe_membership_days';

	/** Momento en el que la línea se revocó por cancelación o reembolso total. */
	public const META_LINE_REVOKED = '_hwe_membership_revoked_at';

	/** Estado del procesamiento del pedido (`processed`/`reversed`), para la caja del pedido. */
	public const META_ORDER_STATE = '_hwe_membership_order_state';

	public const STATUS_PROCESSED = 'processed';
	public const STATUS_REVERSED  = 'reversed';

	/** Motivos estables de rechazo (spec §5.3). */
	public const REASON_LOGIN_REQUIRED     = 'membership_login_required';
	public const REASON_ONE_PER_CART       = 'membership_one_per_cart';
	public const REASON_NOT_PURCHASABLE    = 'membership_not_purchasable';
	public const REASON_ACCOUNT_TOO_NEW    = 'membership_account_too_new';
	public const REASON_ACCOUNT_PENDING    = 'membership_account_pending';
	public const REASON_ALREADY_INDEFINITE = 'membership_already_indefinite';

	/** Estados de WooCommerce que pueden considerarse cobrados. */
	private const COLLECTED_STATUSES = array( 'processing', 'completed' );

	/** Engancha el ciclo del pedido y la validación del carrito. Lo llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		add_action( 'woocommerce_order_status_processing', array( self::class, 'onOrderProcessing' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'onOrderCompleted' ) );
		add_action( 'woocommerce_payment_complete', array( self::class, 'onPaymentComplete' ) );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'onOrderCancelled' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'onOrderRefunded' ), 20 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'snapshotLineItem' ), 10, 4 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( self::class, 'validateAddToCart' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'validateClassicAddToCart' ), 10, 5 );
		add_action( 'woocommerce_store_api_cart_errors', array( self::class, 'validateCart' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'validateOrderRequest' ), 10, 2 );
	}

	// ------------------------------------------------------------------------
	//  Hooks de WooCommerce
	// ------------------------------------------------------------------------

	public static function onOrderProcessing( int $orderId ): void {
		self::processOrder( $orderId );
	}

	public static function onOrderCompleted( int $orderId ): void {
		self::processOrder( $orderId );
	}

	public static function onPaymentComplete( int $orderId ): void {
		self::processOrder( $orderId );
	}

	public static function onOrderCancelled( int $orderId ): void {
		self::reverseOrder( $orderId );
	}

	/** Solo un reembolso TOTAL revierte. Un cambio manual a `refunded` sin filas se trata como total. */
	public static function onOrderRefunded( int $orderId ): void {
		$order = self::order( $orderId );
		if ( null === $order ) {
			self::log( 'no se pudo cargar el pedido ' . $orderId . ' para revertir su membresía.' );
			return;
		}

		if ( ! self::isFullRefund( $order ) ) {
			self::log( 'el pedido ' . $orderId . ' tiene un reembolso parcial; no se revierte su membresía.' );
			return;
		}

		self::reverseOrder( $orderId );
	}

	// ------------------------------------------------------------------------
	//  Reglas de compra
	// ------------------------------------------------------------------------

	/**
	 * ¿Puede el usuario comprar ese nivel? Devuelve siempre `ok`, `reason` (vacío si se puede) y
	 * los datos que explican la decisión (`days_left`, antigüedad, nivel actual…).
	 *
	 * Orden de comprobación: sesión → nivel vendible → cuenta existente → pendiente → antigüedad →
	 * nivel indefinido ya cubierto. La congelación NO bloquea la compra.
	 *
	 * @return array{ok:bool,reason:string,level_id:int,days_left:int,min_account_age_days:int,account_age_days:?int,current_level_id:int}
	 */
	public static function canPurchase( int $userId, int $levelId ): array {
		$result = array(
			'ok'                   => false,
			'reason'               => '',
			'level_id'             => $levelId,
			'days_left'            => 0,
			'min_account_age_days' => 0,
			'account_age_days'     => null,
			'current_level_id'     => 0,
		);

		if ( $userId <= 0 ) {
			$result['reason'] = self::REASON_LOGIN_REQUIRED;
			return $result;
		}

		$levels = class_exists( 'HWE_Memberships_Levels' );
		$level  = $levels ? HWE_Memberships_Levels::get( $levelId ) : null;
		if ( null === $level || ! HWE_Memberships_Levels::isPurchasable( $levelId ) ) {
			$result['reason'] = self::REASON_NOT_PURCHASABLE;
			return $result;
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $userId ) : false;
		if ( ! is_object( $user ) ) {
			// Falla cerrada: sin cuenta no se puede evaluar la antigüedad ni el estado.
			$result['reason'] = self::REASON_LOGIN_REQUIRED;
			return $result;
		}

		$minAge         = max( 0, (int) ( $level['min_account_age_days'] ?? 0 ) );
		$accountAgeDays = self::accountAgeDays( $user );
		$result['min_account_age_days'] = $minAge;
		$result['account_age_days']     = $accountAgeDays;

		if ( self::accountPending( $userId ) ) {
			$result['reason'] = self::REASON_ACCOUNT_PENDING;
			return $result;
		}

		if ( $accountAgeDays < $minAge ) {
			$result['reason']    = self::REASON_ACCOUNT_TOO_NEW;
			$result['days_left'] = max( 0, $minAge - $accountAgeDays );
			return $result;
		}

		$covered = self::indefiniteLevelCovering( $userId, (int) ( $level['rank'] ?? 0 ) );
		if ( null !== $covered ) {
			$result['reason']           = self::REASON_ALREADY_INDEFINITE;
			$result['current_level_id'] = $covered;
			return $result;
		}

		$result['ok'] = true;

		return $result;
	}

	/**
	 * Valida un alta en el carrito de la Store API. Lanza `RouteException` con el motivo estable.
	 *
	 * @param mixed $cart
	 * @param mixed $request
	 */
	public static function validateAddToCart( $cart, $request ): void {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_param' ) ) {
			return;
		}

		$productId   = (int) $request->get_param( 'id' );
		$variationId = (int) $request->get_param( 'variation_id' );
		if ( $variationId > 0 ) {
			$productId = $variationId;
		}

		$membership = self::membershipOfProduct( $productId );
		if ( null === $membership ) {
			return;
		}

		$rawQuantity = $request->get_param( 'quantity' );
		$quantity    = is_numeric( $rawQuantity ) ? (int) $rawQuantity : 1;
		if ( 1 !== $quantity ) {
			throw self::error( self::REASON_ONE_PER_CART, array( 'level_id' => $membership['level_id'] ) );
		}

		if ( self::cartHasMembership( $cart ) ) {
			throw self::error( self::REASON_ONE_PER_CART, array( 'level_id' => $membership['level_id'] ) );
		}

		self::assertCanPurchase( self::currentUserId(), $membership['level_id'] );
	}

	/**
	 * Respaldo para el checkout clásico: la Store API no interviene, así que el carrito se valida
	 * aquí y se avisa con `wc_add_notice()`. Los motivos no viajan estructurados por esta vía.
	 *
	 * @param mixed $passed
	 * @param mixed $productId
	 * @param mixed $quantity
	 * @param mixed $variationId
	 * @param mixed $variation
	 */
	public static function validateClassicAddToCart( $passed, $productId, $quantity, $variationId = 0, $variation = array() ): bool {
		$id = (int) $variationId > 0 ? (int) $variationId : (int) $productId;
		$membership = self::membershipOfProduct( $id );
		if ( null === $membership ) {
			return (bool) $passed;
		}

		$reason = '';
		$data   = array( 'level_id' => $membership['level_id'] );

		if ( 1 !== (int) $quantity ) {
			$reason = self::REASON_ONE_PER_CART;
		} else {
			$cart = self::currentCart();
			if ( null !== $cart && self::cartHasMembership( $cart ) ) {
				$reason = self::REASON_ONE_PER_CART;
			} else {
				$result = self::canPurchase( self::currentUserId(), $membership['level_id'] );
				if ( ! $result['ok'] ) {
					$reason = $result['reason'];
					$data   = $result;
				}
			}
		}

		if ( '' === $reason ) {
			return (bool) $passed;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::message( $reason, $data ), 'error' );
		}
		self::log( 'compra de membresía rechazada en el checkout clásico: ' . $reason . '.' );

		return false;
	}

	/**
	 * Errores del carrito que la Store API devuelve antes de crear el pedido. Acumula un error por
	 * motivo (sin duplicar) con su código estable y sus datos.
	 *
	 * @param mixed $errors
	 * @param mixed $cart
	 */
	public static function validateCart( $errors, $cart ): void {
		if ( ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
			return;
		}

		$lines = self::cartLines( $cart );
		if ( array() === $lines ) {
			return;
		}

		$seen   = array();
		$userId = self::currentUserId();

		if ( count( $lines ) > 1 ) {
			self::addError( $errors, $seen, self::REASON_ONE_PER_CART, array( 'lines' => count( $lines ) ) );
		}

		foreach ( $lines as $line ) {
			if ( 1 !== $line['quantity'] ) {
				self::addError( $errors, $seen, self::REASON_ONE_PER_CART, array( 'level_id' => $line['level_id'] ) );
			}

			$result = self::canPurchase( $userId, $line['level_id'] );
			if ( ! $result['ok'] ) {
				self::addError( $errors, $seen, $result['reason'], $result );
			}
		}
	}

	/**
	 * Revalida las líneas del pedido recién creado por la Store API, antes de cobrarlo. Una línea
	 * bloqueada cancela el checkout con `RouteException` (Foco de revisión 3).
	 *
	 * @param mixed $order
	 * @param mixed $request
	 */
	public static function validateOrderRequest( $order, $request ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$lines = self::membershipLines( $order );
		if ( array() === $lines ) {
			return;
		}

		if ( count( $lines ) > 1 ) {
			throw self::error( self::REASON_ONE_PER_CART, array( 'lines' => count( $lines ) ) );
		}

		$userId = (int) $order->get_customer_id();
		foreach ( $lines as $line ) {
			$quantity = method_exists( $line['item'], 'get_quantity' ) ? (int) $line['item']->get_quantity() : 1;
			if ( 1 !== $quantity ) {
				throw self::error( self::REASON_ONE_PER_CART, array( 'level_id' => $line['level_id'] ) );
			}
			self::assertCanPurchase( $userId, $line['level_id'] );
		}
	}

	/**
	 * Copia el nivel y la duración del producto a la línea del pedido: la compra queda con la
	 * configuración del momento, aunque después se edite el producto.
	 *
	 * @param mixed $item
	 * @param mixed $cartItemKey
	 * @param mixed $values
	 * @param mixed $order
	 */
	public static function snapshotLineItem( $item, $cartItemKey = '', $values = array(), $order = null ): void {
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		$values     = is_array( $values ) ? $values : array();
		$productId  = (int) ( $values['variation_id'] ?? 0 );
		if ( $productId <= 0 ) {
			$productId = (int) ( $values['product_id'] ?? 0 );
		}
		if ( $productId <= 0 && method_exists( $item, 'get_product_id' ) ) {
			$productId = (int) $item->get_product_id();
		}

		$membership = self::membershipOfProduct( $productId );
		if ( null === $membership ) {
			return;
		}

		$item->add_meta_data( self::META_LINE_LEVEL, (int) $membership['level_id'], true );
		$item->add_meta_data( self::META_LINE_DAYS, null === $membership['days'] ? 0 : (int) $membership['days'], true );
	}

	// ------------------------------------------------------------------------
	//  Activación
	// ------------------------------------------------------------------------

	/**
	 * Activa las membresías de un pedido cobrado. Idempotente por línea: la meta de la línea y la
	 * clave única de la concesión impiden conceder dos veces.
	 */
	public static function processOrder( int $orderId ): void {
		$order = self::order( $orderId );
		if ( null === $order ) {
			self::log( 'no se pudo cargar el pedido ' . $orderId . ' para activar su membresía.' );
			return;
		}

		if ( ! self::isCollected( $order ) ) {
			return;
		}

		$lines = self::membershipLines( $order );
		if ( array() === $lines ) {
			return;
		}

		$userId = (int) $order->get_customer_id();
		if ( $userId <= 0 ) {
			self::log( 'el pedido ' . $orderId . ' es de invitado: sus líneas de membresía se registran y se ignoran.' );
			return;
		}

		$state = self::state( $order );
		if ( in_array( (string) ( $state['status'] ?? '' ), array( self::STATUS_PROCESSED, self::STATUS_REVERSED ), true ) ) {
			return;
		}

		$processed = array();
		foreach ( $lines as $line ) {
			$item    = $line['item'];
			$grantId = method_exists( $item, 'get_meta' ) ? (int) $item->get_meta( self::META_LINE_GRANT ) : 0;
			$key     = self::lineKey( $orderId, $line['item_id'] );

			if ( $grantId <= 0 ) {
				$newId = class_exists( 'HWE_Memberships_Grants' )
					? HWE_Memberships_Grants::grant(
						$userId,
						$line['level_id'],
						$line['days'],
						HWE_Memberships_Grants::ORIGIN_ORDER,
						$key,
						array(
							'type' => 'order',
							'id'   => $orderId,
						),
						0,
						array(
							'order'   => $orderId,
							'item'    => $line['item_id'],
							'product' => $line['product_id'],
						)
					)
					: null;

				if ( null !== $newId ) {
					$grantId = $newId;
				} else {
					// La clave ya existía (dos avisos a la vez) o falló: si existe, se adopta.
					$existing = self::grantByKey( $userId, $key );
					if ( null !== $existing ) {
						$grantId = (int) $existing['id'];
					}
				}
			}

			if ( $grantId <= 0 ) {
				self::log( 'no se pudo conceder la membresía de la línea ' . $line['item_id'] . ' del pedido ' . $orderId . '.' );
				continue;
			}

			self::writeLine( $item, array(
				self::META_LINE_GRANT => $grantId,
				self::META_LINE_LEVEL => (int) $line['level_id'],
				self::META_LINE_DAYS  => (int) $line['days'],
			) );

			$processed[] = array(
				'item'     => (int) $line['item_id'],
				'product'  => (int) $line['product_id'],
				'level_id' => (int) $line['level_id'],
				'days'     => (int) $line['days'],
				'grant_id' => $grantId,
				'key'      => $key,
			);
		}

		if ( array() === $processed ) {
			return;
		}

		self::writeState( $order, array(
			'version'       => 1,
			'status'        => self::STATUS_PROCESSED,
			'items'         => $processed,
			'processed_at'  => self::now(),
		) );
	}

	/**
	 * Revoca las concesiones de un pedido cancelado o reembolsado del todo. Solo actúa sobre un
	 * pedido que ya activó; no toca otras concesiones del usuario ni las acorta.
	 */
	public static function reverseOrder( int $orderId ): void {
		$order = self::order( $orderId );
		if ( null === $order ) {
			self::log( 'no se pudo cargar el pedido ' . $orderId . ' para revertir su membresía.' );
			return;
		}

		$state = self::state( $order );
		if ( self::STATUS_PROCESSED !== (string) ( $state['status'] ?? '' ) ) {
			return;
		}

		$userId = (int) $order->get_customer_id();
		if ( $userId <= 0 ) {
			self::log( 'el pedido ' . $orderId . ' es de invitado: no hay membresía que revertir.' );
			return;
		}

		$revoked = array();
		foreach ( self::membershipLines( $order ) as $line ) {
			$key = self::lineKey( $orderId, $line['item_id'] );
			if ( ! class_exists( 'HWE_Memberships_Grants' ) ) {
				break;
			}
			if ( ! HWE_Memberships_Grants::revokeByKey( $key, 0, 'order_cancelled_or_refunded' ) ) {
				continue;
			}
			$revoked[] = $key;
			self::writeLine( $line['item'], array( self::META_LINE_REVOKED => self::now() ) );
		}

		if ( array() === $revoked ) {
			return;
		}

		$state['status']      = self::STATUS_REVERSED;
		$state['reversed_at'] = self::now();
		$state['revoked']     = $revoked;
		self::writeState( $order, $state );
	}

	// ------------------------------------------------------------------------
	//  Cuándo cuenta un pedido
	// ------------------------------------------------------------------------

	/**
	 * ¿El pedido está cobrado? `processing` o `completed` con `is_paid()`; los medios del filtro
	 * `hwe_memberships_collected_on_completion` (por defecto `cod`) solo cuentan en `completed`.
	 */
	public static function isCollected( WC_Order $order ): bool {
		$status = (string) $order->get_status();
		if ( ! in_array( $status, self::COLLECTED_STATUSES, true ) ) {
			return false;
		}

		if ( ! $order->is_paid() ) {
			return false;
		}

		/** @var mixed $deferred */
		$deferred = function_exists( 'apply_filters' )
			? apply_filters( 'hwe_memberships_collected_on_completion', array( 'cod' ) )
			: array( 'cod' );
		if ( ! is_array( $deferred ) ) {
			self::log( 'el filtro hwe_memberships_collected_on_completion devolvió ' . get_debug_type( $deferred ) . '; se usa cod.' );
			$deferred = array( 'cod' );
		}

		$deferred = array_values( array_filter( array_map(
			static fn ( mixed $method ): string => is_scalar( $method ) ? strtolower( trim( (string) $method ) ) : '',
			$deferred
		) ) );

		$method = strtolower( trim( (string) $order->get_payment_method() ) );
		if ( 'completed' !== $status && '' !== $method && in_array( $method, $deferred, true ) ) {
			return false;
		}

		return true;
	}

	// ------------------------------------------------------------------------
	//  Piezas privadas
	// ------------------------------------------------------------------------

	/**
	 * Líneas de membresía del pedido, con el nivel y la duración comprados. Si la línea trae la
	 * foto de la compra se usa esa; si no (pedidos anteriores a esta tarea), se resuelve desde el
	 * producto y se guardará al conceder.
	 *
	 * @return list<array{item:object,item_id:int,product_id:int,level_id:int,days:int}>
	 */
	private static function membershipLines( $order ): array {
		$lines = array();

		foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_id' ) ) {
				continue;
			}
			$itemId = (int) $item->get_id();
			if ( $itemId <= 0 ) {
				continue;
			}

			$productId = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$levelId   = method_exists( $item, 'get_meta' ) ? (int) $item->get_meta( self::META_LINE_LEVEL ) : 0;
			$days      = 0;

			if ( $levelId > 0 ) {
				$raw  = $item->get_meta( self::META_LINE_DAYS );
				$days = is_numeric( $raw ) ? max( 0, (int) $raw ) : 0;
			} else {
				$membership = self::membershipOfProduct( $productId );
				if ( null === $membership ) {
					continue;
				}
				$levelId = (int) $membership['level_id'];
				$days    = null === $membership['days'] ? 0 : (int) $membership['days'];
			}

			$lines[] = array(
				'item'       => $item,
				'item_id'    => $itemId,
				'product_id' => $productId,
				'level_id'   => $levelId,
				'days'       => $days,
			);
		}

		return $lines;
	}

	/** @return list<array{key:string,product_id:int,quantity:int,level_id:int}> */
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
			$membership = self::membershipOfProduct( $productId );
			if ( null === $membership ) {
				continue;
			}
			$lines[] = array(
				'key'        => (string) $key,
				'product_id' => $productId,
				'quantity'   => max( 1, (int) ( $cartItem['quantity'] ?? 1 ) ),
				'level_id'   => (int) $membership['level_id'],
			);
		}

		return $lines;
	}

	private static function cartHasMembership( mixed $cart ): bool {
		return array() !== self::cartLines( $cart );
	}

	/** @return array{level_id:int,days:?int}|null */
	private static function membershipOfProduct( int $productId ): ?array {
		if ( $productId <= 0 || ! class_exists( 'HWE_Memberships_Products' ) ) {
			return null;
		}

		$membership = HWE_Memberships_Products::membershipOf( $productId );
		if ( null !== $membership ) {
			return $membership;
		}

		// Una variación hereda el vínculo de su producto padre, si lo tuviera.
		if ( function_exists( 'wp_get_post_parent_id' ) ) {
			$parentId = (int) wp_get_post_parent_id( $productId );
			if ( $parentId > 0 ) {
				return HWE_Memberships_Products::membershipOf( $parentId );
			}
		}

		return null;
	}

	/**
	 * Nivel indefinido (activo o programado, sin fin) del usuario que ya cubre el rango pedido.
	 * Devuelve su id o `null`.
	 */
	private static function indefiniteLevelCovering( int $userId, int $targetRank ): ?int {
		if ( $targetRank < 1 || ! class_exists( 'HWE_Memberships_Grants' ) ) {
			return null;
		}

		foreach ( HWE_Memberships_Grants::forUser( $userId ) as $grant ) {
			if ( null !== ( $grant['ends_at'] ?? null ) ) {
				continue;
			}
			if ( (int) ( $grant['rank'] ?? 0 ) < $targetRank ) {
				continue;
			}
			return (int) ( $grant['level_id'] ?? 0 );
		}

		return null;
	}

	/** Antigüedad de la cuenta en días completos; una fecha ausente o inválida cuenta como 0. */
	private static function accountAgeDays( object $user ): int {
		$registered = is_scalar( $user->user_registered ?? null ) ? trim( (string) $user->user_registered ) : '';
		if ( '' === $registered ) {
			return 0;
		}
		$timestamp = strtotime( $registered . ' UTC' );
		if ( false === $timestamp ) {
			return 0;
		}

		return max( 0, (int) floor( ( time() - $timestamp ) / DAY_IN_SECONDS ) );
	}

	private static function accountPending( int $userId ): bool {
		if ( ! function_exists( 'apply_filters' ) ) {
			return false;
		}
		/** @var mixed $pending */
		$pending = apply_filters( 'hwe_account_is_pending', false, $userId );

		return (bool) $pending;
	}

	/** Lanza el rechazo con el motivo estable; el BFF compone el texto final. */
	private static function assertCanPurchase( int $userId, int $levelId ): void {
		$result = self::canPurchase( $userId, $levelId );
		if ( ! $result['ok'] ) {
			throw self::error( $result['reason'], $result );
		}
	}

	/** Error de la Store API con código `hwe_<motivo>` y datos estructurados. */
	private static function error( string $reason, array $data = array() ): \Throwable {
		$code    = 'hwe_' . $reason;
		$payload = array_merge( array( 'status' => 400, 'reason' => $reason ), $data );
		$class   = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

		if ( class_exists( $class ) ) {
			return new $class( $code, self::message( $reason, $data ), 400, $payload );
		}

		self::log( 'la Store API no está disponible; se rechaza la compra con «' . $reason . '».' );

		return new \RuntimeException( self::message( $reason, $data ) );
	}

	/** @param array<string,mixed> $data */
	private static function addError( object $errors, array &$seen, string $reason, array $data = array() ): void {
		if ( isset( $seen[ $reason ] ) ) {
			return;
		}
		$seen[ $reason ] = true;

		if ( method_exists( $errors, 'add' ) ) {
			$errors->add( 'hwe_' . $reason, self::message( $reason, $data ), array_merge( array( 'status' => 400 ), $data ) );
		}
	}

	/**
	 * Texto de respaldo en español. La UI del BFF no usa estas frases: compone las suyas con
	 * `messages/` a partir del código y de los datos estructurados.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function message( string $reason, array $data = array() ): string {
		switch ( $reason ) {
			case self::REASON_LOGIN_REQUIRED:
				return __( 'Inicia sesión para comprar una membresía.', 'hwe' );
			case self::REASON_ONE_PER_CART:
				return __( 'Solo puedes llevar una membresía por compra, con cantidad 1.', 'hwe' );
			case self::REASON_NOT_PURCHASABLE:
				return __( 'Ese nivel de membresía no está disponible para comprar.', 'hwe' );
			case self::REASON_ACCOUNT_TOO_NEW:
				return sprintf(
					/* translators: %d: días que faltan. */
					__( 'Tu cuenta necesita %d días más para comprar este nivel.', 'hwe' ),
					max( 0, (int) ( $data['days_left'] ?? 0 ) )
				);
			case self::REASON_ACCOUNT_PENDING:
				return __( 'Tu cuenta está pendiente de aprobación.', 'hwe' );
			case self::REASON_ALREADY_INDEFINITE:
				return __( 'Ya tienes ese nivel de membresía, o uno superior, sin caducidad.', 'hwe' );
			default:
				return __( 'No se puede comprar esta membresía.', 'hwe' );
		}
	}

	/** @return array<string,mixed> */
	private static function state( WC_Order $order ): array {
		$raw = $order->get_meta( self::META_ORDER_STATE );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/** @param array<string,mixed> $state */
	private static function writeState( WC_Order $order, array $state ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save_meta_data' ) ) {
			self::log( 'el pedido ' . (int) $order->get_id() . ' no admite metas; no se guardó el estado de la membresía.' );
			return;
		}

		$order->update_meta_data( self::META_ORDER_STATE, $state );
		$order->save_meta_data();
	}

	/**
	 * Escribe las metas de una línea con la API de WooCommerce y la guarda (HPOS).
	 *
	 * @param array<string,mixed> $meta
	 */
	private static function writeLine( object $item, array $meta ): void {
		if ( ! method_exists( $item, 'update_meta_data' ) ) {
			return;
		}
		foreach ( $meta as $key => $value ) {
			$item->update_meta_data( $key, $value );
		}
		if ( method_exists( $item, 'save' ) ) {
			$item->save();
		}
	}

	/** @return array<string,mixed>|null */
	private static function grantByKey( int $userId, string $key ): ?array {
		if ( ! class_exists( 'HWE_Memberships_Grants' ) ) {
			return null;
		}
		foreach ( HWE_Memberships_Grants::forUser( $userId ) as $grant ) {
			if ( (string) ( $grant['key'] ?? '' ) === $key ) {
				return $grant;
			}
		}

		return null;
	}

	private static function lineKey( int $orderId, int $itemId ): string {
		return 'order:' . $orderId . ':item:' . $itemId;
	}

	/** ¿Es un reembolso total? Sin filas de reembolso se trata como el cambio manual a `refunded`. */
	private static function isFullRefund( WC_Order $order ): bool {
		$refunded = (float) $order->get_total_refunded();
		if ( $refunded <= 0 ) {
			return true;
		}

		return $refunded + 0.01 >= (float) $order->get_total();
	}

	private static function currentUserId(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/** Carrito clásico; `null` si no hay sesión de WooCommerce. */
	private static function currentCart(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$woocommerce = WC();

		return is_object( $woocommerce ) && isset( $woocommerce->cart ) && is_object( $woocommerce->cart )
			? $woocommerce->cart
			: null;
	}

	private static function order( int $orderId ): ?WC_Order {
		if ( $orderId <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $orderId );

		return $order instanceof WC_Order ? $order : null;
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s', time() );
	}

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
