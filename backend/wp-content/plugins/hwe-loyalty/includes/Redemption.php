<?php
/**
 * Canje de puntos en el carrito y en el pedido (spec §7.1–§7.5 y §5.3).
 *
 * **Un único camino de descuento.** El navegador nunca envía importes: solo declara cuántos
 * puntos quiere usar en el update callback del namespace `hwe-loyalty` de la Store API, que los
 * guarda en la sesión de WooCommerce (`0` = quitar). El descuento real nace en
 * `woocommerce_cart_calculate_fees` como un fee negativo NO imponible, recalculado en cada
 * recálculo del carrito. El fee no toca las líneas de producto, así que la base de las
 * ganancias (`HWE_Loyalty_Earnings`, que resta `_hwe_loyalty_discount`) sigue siendo correcta.
 *
 * **Recorte.** Los puntos pedidos se recortan, en este orden, a: saldo vivo del libro, máximo
 * por pedido (`max_redeem_per_order`, 0 = sin tope), porcentaje de la base sin envío
 * (`max_redeem_percent`, 0 = sin tope) y tope del carrito. Con el pago completo
 * apagado el descuento no cubre el envío y deja siempre al menos `min_cash_due` por pagar en
 * dinero (también con envío 0); un carrito por debajo de ese mínimo no admite canje. Con el
 * pago completo encendido el tope es el total con envío y puede llegar a 0. Por debajo de
 * `min_redeem` no se aplica. El descuento es `puntos × point_value` redondeado a la precisión
 * de la moneda de Woo; nunca se cargan más puntos que el valor del descuento aplicado. Cuando
 * el pago completo llega a 0 y el total no es múltiplo exacto del valor del punto, el punto
 * que falta para cubrirlo se regala (bonus menor que un punto): es la única forma de no cobrar
 * puntos por encima del descuento.
 *
 * **Contrato de la extensión.** `extensions.hwe-loyalty` del carrito expone `available`,
 * `reason`, `balance`, `points_applied`, `discount` (unidades menores, string, como el resto
 * de la Store API), `max_redeemable`, `min_redeem` y `full_payment_available`.
 * `available` dice si el programa puede usarse en este carrito y usuario; `reason` explica por
 * qué el canje actual no produce descuento. Precedencia de `reason`:
 * `disabled` (módulo/Puntos apagados o `point_value` ≤ 0) → `guest` → `not_participant` →
 * `cart_below_cash_minimum` → `below_minimum` (el máximo canjeable o lo pedido no llega al
 * mínimo). Un invitado o un rol no participante nunca recibe saldo ni fee.
 *
 * **Confirmación al crear el pedido.** Hook elegido:
 * `woocommerce_store_api_checkout_order_processed`. En ese punto el pedido ya existe con id
 * (la Store API lo crea como borrador), todavía no se ha intentado el cobro y lanzar una
 * `RouteException` aborta el checkout con un error limpio; la Store API guarda el borrador
 * para el reintento. Antes del cargo se recalcula el canje con los totales del pedido y el
 * saldo vivo: si el saldo ya no alcanza para el descuento que el cliente tenía aplicado, se
 * quita el fee del pedido (no nace un descuento sin respaldo) y se lanza el error (Foco 1).
 * Si alcanza, se carga con la clave `redeem:order:<id>` y se escriben las metas
 * `_hwe_loyalty_redeemed_points`, `_hwe_loyalty_discount` (reutiliza la constante de
 * `HWE_Loyalty_Earnings`) y `_hwe_loyalty_paid_with_points` (`yes` si el total quedó en 0).
 *
 * **Borrador reutilizado.** La Store API reutiliza el pedido `checkout-draft` entre reintentos.
 * El primer intento usa `redeem:order:<id>`; si el mismo borrador se reenvía con los mismos
 * puntos, la clave única del libro lo hace idempotente y el fee se sincroniza con el descuento
 * ya respaldado. Si el reenvío pide otros puntos, el canje ya confirmado queda fijado (no se
 * carga dos veces ni se cambia el descuento). Un fallo de saldo antes de cargar aborta el
 * checkout. Tras una devolución, un reintento del mismo pedido usa una clave nueva
 * (`redeem:order:<id>:<n>` y `redeem_refund:order:<id>:<n>`) para no reutilizar un cargo ya
 * devuelto; el primer intento conserva siempre las claves canónicas.
 *
 * **Devolución.** `cancelled`, `failed` y `refunded` devuelven los puntos con
 * `HWE_Loyalty_Ledger::reverseDebit` y la clave `redeem_refund:order:<id>`, una sola vez
 * (idempotente). Un reembolso parcial no devuelve (limitación documentada). Si el pedido no
 * estaba pagado, además se quita el fee y el descuento del pedido para que un reintento no lo
 * reutilice sin puntos. Las notas privadas del pedido componen el nombre de la unidad desde
 * `currency_name` / `currency_name_plural`: nunca está escrito a mano.
 *
 * La extensión solo se registra con el módulo cargado; con Puntos apagados o `point_value` ≤ 0
 * el estado responde `available: false` con `reason: disabled` y el fee no se aplica (Foco 5).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Redemption {

	/** Namespace de la extensión de la Store API. */
	public const NAMESPACE_ID = 'hwe-loyalty';

	/**
	 * Filtro estable con el que una instancia endurece el máximo canjeable ya calculado
	 * (saldo, `max_redeem_per_order`, `min_cash_due`/tope de caja y `max_redeem_percent`).
	 */
	public const FILTER_MAX_REDEEMABLE = 'hwe_loyalty_max_redeemable';

	/** Clave de la sesión de WooCommerce con los puntos pedidos. */
	public const SESSION_KEY = 'hwe_loyalty_points';

	/**
	 * Puntos que el cliente vio aplicados al pedirlos (`updatePoints`). El checkout
	 * recalcula el carrito antes de crear el pedido y `applyFee` recorta al saldo vivo: sin
	 * esto, un saldo que bajó entremedias borraría el fee y el pedido saldría a precio completo
	 * sin aviso (Foco 1).
	 */
	public const SHOWN_SESSION_KEY = 'hwe_loyalty_points_shown';

	/** Prefijo del nombre visible del fee (y de las líneas de fee del pedido). */
	public const FEE_PREFIX = 'Descuento de fidelización: ';

	/** Puntos confirmados para el pedido (0 tras una devolución). */
	public const META_REDEEMED_POINTS = '_hwe_loyalty_redeemed_points';

	/** Último intento de canje confirmado del pedido (para claves de reintento). */
	public const META_REDEEM_ATTEMPT = '_hwe_loyalty_redeem_attempt';

	/** Meta interna de la Store API con el hash de los fees copiados del carrito. */
	private const META_FEES_HASH = '_fees_hash';

	/** Código de error de la Store API cuando el saldo ya no alcanza. */
	private const ERROR_INSUFFICIENT = 'hwe_loyalty_insufficient_points';

	/** Código de error de la Store API para una cantidad de puntos inválida. */
	private const ERROR_INVALID = 'hwe_loyalty_invalid_points';

	/** Engancha la extensión, el fee, la confirmación y la devolución. */
	public static function register(): void {
		// `HWE_Loyalty::boot()` corre en `plugins_loaded` (prioridad 20), después de que
		// WooCommerce dispare `woocommerce_blocks_loaded`: si ya ocurrió, se registra en el acto.
		if ( function_exists( 'did_action' ) && did_action( 'woocommerce_blocks_loaded' ) ) {
			self::registerStoreApi();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'registerStoreApi' ) );
		}
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'applyFee' ) );
		add_filter( 'woocommerce_cart_totals_get_fees_from_cart_taxes', array( self::class, 'feeTaxes' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'confirmOrder' ) );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'onOrderCancelled' ) );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'onOrderFailed' ) );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'onOrderRefunded' ) );
	}

	// ------------------------------------------------------------------------
	//  Store API: extensión y update callback
	// ------------------------------------------------------------------------

	/**
	 * Declara `extensions.hwe-loyalty` en el carrito y el update callback del namespace.
	 * Lo llama `register()`: en el acto o en `woocommerce_blocks_loaded` si aún no ocurrió.
	 */
	public static function registerStoreApi(): void {
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
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
					'callback'  => array( self::class, 'updatePoints' ),
				)
			);
		}
	}

	/**
	 * Update callback del namespace: guarda los puntos pedidos en la sesión de WooCommerce.
	 * El navegador solo declara la cantidad; el importe lo decide `applyFee()`.
	 *
	 * @param mixed $data Datos del endpoint `cart/extensions` (`{ points: int }`).
	 * @throws \Throwable Cuando la cantidad no es un entero ≥ 0.
	 */
	public static function updatePoints( mixed $data ): void {
		$payload = is_array( $data ) ? $data : array();
		$points  = self::normalizePoints( $payload['points'] ?? 0 );

		if ( null === $points ) {
			throw self::error(
				self::ERROR_INVALID,
				sprintf(
					'La cantidad de %s del canje no es válida.',
					self::unitName( 0, HWE_Loyalty_Settings::get() )
				)
			);
		}

		$session = self::session();
		if ( null === $session ) {
			self::log( 'no hay sesión de WooCommerce para guardar la cantidad pedida.' );
			return;
		}

		$session->set( self::SESSION_KEY, $points );
		// Lo que el cliente pasa a ver aplicado al pedir sus puntos. Se registra AQUÍ y no en
		// cada respuesta del carrito: el BFF lee el carrito justo antes del checkout y esa
		// lectura, con un saldo que ya bajó, borraría lo que el cliente vio (Foco 1).
		$session->set( self::SHOWN_SESSION_KEY, (int) self::computeState()['points_applied'] );
	}

	/**
	 * Estado del canje para `extensions.hwe-loyalty`. Importes en unidades MENORES (string),
	 * como el resto de la Store API.
	 *
	 * @return array<string,mixed>
	 */
	public static function cartState(): array {
		$state = self::computeState();

		return array(
			'available'              => (bool) $state['available'],
			'reason'                 => $state['reason'],
			'balance'                => (int) $state['balance'],
			'points_applied'         => (int) $state['points_applied'],
			'discount'               => self::toMinorUnits( (float) $state['discount'] ),
			'max_redeemable'         => (int) $state['max_redeemable'],
			'min_redeem'             => (int) $state['min_redeem'],
			'full_payment_available' => (bool) $state['full_payment_available'],
		);
	}

	/**
	 * Estado del canje del carrito actual, sin efectos. Lo usan `cartState()` (cada respuesta
	 * del carrito) y `updatePoints()` (lo que el cliente pasa a ver al pedir sus puntos).
	 *
	 * @return array<string,mixed>
	 */
	private static function computeState(): array {
		$settings = HWE_Loyalty_Settings::get();
		$cart     = self::cart();

		if ( null === $cart ) {
			return self::emptyState( $settings );
		}

		$loyaltyDiscount = self::cartLoyaltyDiscount( $cart );
		// `'edit'`: en el contexto por defecto (`view`) WooCommerce devuelve el HTML de `wc_price`.
		$total           = (float) $cart->get_total( 'edit' );
		$totalWithout    = max( 0.0, $total + $loyaltyDiscount );
		$shipping        = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		$items           = max( 0.0, $totalWithout - $shipping );

		$userId      = self::currentUserId();
		$active      = HWE_Loyalty_Settings::redeemActive();
		$participant = $userId > 0 && $active && HWE_Loyalty_Settings::canParticipate( $userId );
		$balance     = $participant ? (int) HWE_Loyalty_Ledger::balance( $userId )['balance'] : 0;

		$state = self::quote(
			array(
				'items_total'    => $items,
				'shipping_total' => $shipping,
				'fees_total'     => 0.0,
				'balance'        => $balance,
				'requested'      => self::requestedPoints(),
				'logged_in'      => $userId > 0,
				'participant'    => $participant,
				'active'         => $active,
			),
			$settings
		);

		return $state;
	}

	/**
	 * Esquema de la extensión para la Store API.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function cartStateSchema(): array {
		$text = static function ( string $description ): array {
			return array(
				'description' => $description,
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			);
		};
		$flag = static function ( string $description ): array {
			return array(
				'description' => $description,
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			);
		};
		$int  = static function ( string $description ): array {
			return array(
				'description' => $description,
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			);
		};
		$money = static function ( string $description ): array {
			return array(
				'description' => $description,
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			);
		};

		return array(
			'available'              => $flag( 'El canje puede usarse en este carrito y usuario.' ),
			'reason'                 => $text( 'Motivo por el que el canje actual no produce descuento (null si no hay bloqueo).' ),
			'balance'                => $int( 'Saldo disponible en unidades.' ),
			'points_applied'         => $int( 'Unidades efectivamente aplicadas al carrito.' ),
			'discount'               => $money( 'Descuento aplicado, en unidades menores de la moneda.' ),
			'max_redeemable'         => $int( 'Máximo de unidades canjeables en este carrito.' ),
			'min_redeem'             => $int( 'Mínimo de unidades para canjear.' ),
			'full_payment_available' => $flag( 'El pago completo con la unidad está activo y este carrito puede llegar a 0.' ),
		);
	}

	// ------------------------------------------------------------------------
	//  Cálculo (fee del carrito)
	// ------------------------------------------------------------------------

	/**
	 * Fee negativo no imponible con el descuento recortado. WooCommerce lo recalcula en cada
	 * `calculate_totals()`; no persiste ni toca las líneas de producto.
	 *
	 * @param mixed $cart Carrito que pasa `woocommerce_cart_calculate_fees`.
	 */
	public static function applyFee( mixed $cart ): void {
		if (
			! is_object( $cart )
			|| ! method_exists( $cart, 'add_fee' )
			|| ! method_exists( $cart, 'get_fees' )
		) {
			return;
		}
		if ( ! HWE_Loyalty_Settings::redeemActive() ) {
			return;
		}

		$settings = HWE_Loyalty_Settings::get();
		$userId   = self::currentUserId();
		if ( $userId <= 0 || ! HWE_Loyalty_Settings::canParticipate( $userId ) ) {
			return;
		}

		$balance = (int) HWE_Loyalty_Ledger::balance( $userId )['balance'];
		$items   = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
		$shipping = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();

		$state = self::quote(
			array(
				'items_total'    => $items,
				'shipping_total' => $shipping,
				'fees_total'     => self::otherFeesTotal( $cart ),
				'balance'        => $balance,
				'requested'      => self::requestedPoints(),
				'logged_in'      => true,
				'participant'    => true,
				'active'         => true,
			),
			$settings
		);

		if ( $state['points_applied'] <= 0 || $state['discount'] <= 0 ) {
			return;
		}

		$cart->add_fee(
			self::feeName( (int) $state['points_applied'], $settings ),
			-1.0 * (float) $state['discount'],
			false
		);
	}

	/**
	 * El fee de fidelización nunca lleva impuestos. WooCommerce reparte impuestos sobre
	 * CUALQUIER fee negativo aunque no sea imponible (`WC_Cart_Totals::get_fees_from_cart`):
	 * con un IVA del 19 %, un canje de 5000 bajaba el total 5950 y saltaba los topes del canje.
	 * El descuento vale exactamente lo que valen los puntos (spec §7.2: fee no imponible).
	 *
	 * @param mixed $taxes Impuestos calculados para el fee.
	 * @param mixed $fee   Fee de `WC_Cart_Totals` (`->object->name`).
	 * @return mixed
	 */
	public static function feeTaxes( mixed $taxes, mixed $fee = null ): mixed {
		$name = is_object( $fee ) && isset( $fee->object ) && is_object( $fee->object ) && isset( $fee->object->name )
			? (string) $fee->object->name
			: '';

		return '' !== $name && self::isLoyaltyFeeName( $name ) ? array() : $taxes;
	}

	// ------------------------------------------------------------------------
	//  Confirmación en la creación del pedido
	// ------------------------------------------------------------------------

	/**
	 * Confirma el canje contra el libro al crear el pedido de la Store API. Hook:
	 * `woocommerce_store_api_checkout_order_processed` (el pedido ya tiene id, todavía no se
	 * ha cobrado y una `RouteException` aborta el checkout con un error limpio).
	 *
	 * @param mixed $order Pedido que pasa la Store API.
	 * @throws \Throwable Si el saldo ya no alcanza (Foco 1).
	 */
	public static function confirmOrder( mixed $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$orderId = (int) $order->get_id();
		if ( $orderId <= 0 ) {
			self::log( 'no se puede confirmar el canje de un pedido sin id.' );
			return;
		}

		$settings   = HWE_Loyalty_Settings::get();
		$metaPoints = (int) $order->get_meta( self::META_REDEEMED_POINTS );

		// Canje ya confirmado para este pedido: manda el libro, no una nueva petición.
		if ( $metaPoints > 0 ) {
			$discount = (float) $order->get_meta( HWE_Loyalty_Earnings::META_DISCOUNT );
			if ( $discount <= 0 ) {
				$discount = self::orderLoyaltyDiscount( $order );
			}
			if ( $discount <= 0 ) {
				$discount = self::money( $metaPoints * (float) $settings['point_value'] );
			}
			self::syncOrderDiscount( $order, $metaPoints, $discount, $settings );
			self::writeOrderMetas( $order, $metaPoints, $discount, self::currentAttempt( $order ), $settings, false );
			self::forgetRequest();
			return;
		}

		$userId      = (int) $order->get_customer_id();
		$active      = HWE_Loyalty_Settings::redeemActive();
		$feeDiscount = self::orderLoyaltyDiscount( $order );
		// El canje que se confirma es el que el cliente vio: el fee copiado del carrito o, si el
		// recálculo del checkout ya lo recortó por saldo, los puntos que vio aplicados. Sin
		// ninguno de los dos, no hay canje.
		$requested = max(
			self::pointsForDiscount( $feeDiscount, (float) $settings['point_value'] ),
			self::shownPoints()
		);

		if ( ! $active || $userId <= 0 ) {
			self::removeOrderDiscount( $order, $settings );
			return;
		}

		$shipping  = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		$total     = max( 0.0, (float) $order->get_total() + $feeDiscount );
		$items     = max( 0.0, $total - $shipping );
		$desired   = self::quote(
			array(
				'items_total'    => $items,
				'shipping_total' => $shipping,
				'fees_total'     => 0.0,
				// El recorte por saldo se comprueba aparte: aquí interesa el canje que el
				// cliente tenía aplicado, no uno más pequeño calculado con el saldo vivo.
				'balance'        => PHP_INT_MAX,
				'requested'      => $requested,
				'logged_in'      => true,
				'participant'    => HWE_Loyalty_Settings::canParticipate( $userId ),
				'active'         => true,
			),
			$settings
		);

		if ( $desired['points_applied'] <= 0 || $desired['discount'] <= 0 ) {
			self::removeOrderDiscount( $order, $settings );
			return;
		}

		$live = (int) HWE_Loyalty_Ledger::balance( $userId )['balance'];
		if ( $live < (int) $desired['points_applied'] ) {
			self::removeOrderDiscount( $order, $settings );
			self::log(
				'el pedido ' . $orderId . ' no puede confirmar el canje: saldo vivo ' . $live
				. ' < ' . (int) $desired['points_applied'] . ' unidades.'
			);
			// El cliente vuelve al carrito, que ya muestra lo que su saldo cubre: el reintento debe
			// confirmar eso y no repetir el error con la cantidad vieja.
			self::forgetShown();
			throw self::insufficientPoints( $settings );
		}

		$attempt = self::nextAttempt( $order, $orderId );
		$key     = self::debitKey( $orderId, $attempt );
		$entryId = HWE_Loyalty_Ledger::debit(
			$userId,
			(int) $desired['points_applied'],
			'redeem',
			$key,
			array( 'type' => 'order', 'id' => $orderId ),
			array(
				'order'    => $orderId,
				'discount' => self::moneyString( (float) $desired['discount'] ),
				'attempt'  => $attempt,
			)
		);

		if ( null === $entryId ) {
			$existing = self::entryWithKey( $key );
			if ( null !== $existing ) {
				// El cargo ya estaba hecho y las metas no se guardaron (o el reintento llegó
				// justo después). Se recupera el descuento respaldado, sin cargar dos veces.
				$points   = abs( (int) $existing['points'] );
				$data     = self::decodeData( $existing['data'] ?? null );
				$discount = isset( $data['discount'] ) && is_numeric( $data['discount'] )
					? (float) $data['discount']
					: self::money( $points * (float) $settings['point_value'] );
				self::syncOrderDiscount( $order, $points, $discount, $settings );
				self::writeOrderMetas( $order, $points, $discount, $attempt, $settings );
				self::forgetRequest();
				return;
			}

			self::removeOrderDiscount( $order, $settings );
			self::log(
				'el pedido ' . $orderId . ' no pudo cargar las unidades del canje; descuento retirado.'
			);
			// Si el saldo previo alcanzaba, el fallo es de escritura o una carrera con otra
			// pestaña: no se afirma «saldo insuficiente» sin poder demostrarlo.
			throw self::error(
				'hwe_loyalty_redeem_failed',
				'No se pudo confirmar el canje de este pedido. Revisa el carrito e inténtalo de nuevo.'
			);
		}

		self::syncOrderDiscount( $order, (int) $desired['points_applied'], (float) $desired['discount'], $settings );
		self::writeOrderMetas( $order, (int) $desired['points_applied'], (float) $desired['discount'], $attempt, $settings );
		self::forgetRequest();
	}

	// ------------------------------------------------------------------------
	//  Devolución del canje
	// ------------------------------------------------------------------------

	public static function onOrderCancelled( int $orderId ): void {
		self::refundOrder( $orderId, 'cancelled' );
	}

	public static function onOrderFailed( int $orderId ): void {
		self::refundOrder( $orderId, 'failed' );
	}

	public static function onOrderRefunded( int $orderId ): void {
		self::refundOrder( $orderId, 'refunded' );
	}

	/**
	 * Devuelve los puntos de un pedido con canje en `cancelled`, `failed` o `refunded`.
	 * Idempotente: la clave `redeem_refund:order:<id>[:<n>]` evita el doble abono. Un reembolso
	 * parcial no devuelve.
	 */
	public static function refundOrder( int $orderId, string $status = 'cancelled' ): void {
		if ( $orderId <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $orderId );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			self::log( 'no se pudo cargar el pedido ' . $orderId . ' para devolver su canje.' );
			return;
		}

		if ( 'refunded' === $status && ! self::isFullRefund( $order ) ) {
			self::log( 'el pedido ' . $orderId . ' tiene un reembolso parcial; no se devuelve el canje.' );
			return;
		}

		$points      = (int) $order->get_meta( self::META_REDEEMED_POINTS );
		$attemptMeta = (int) $order->get_meta( self::META_REDEEM_ATTEMPT );
		$attempt     = max( 1, $attemptMeta );

		// Si las metas del canje se perdieron tras cargar los puntos, el cargo del último
		// intento (o el canónico) sigue en el libro: se devuelve igualmente (una sola vez).
		if ( $points <= 0 ) {
			$debit = self::entryWithKey( self::debitKey( $orderId, $attempt ) );
			if ( null === $debit || null !== self::entryWithKey( self::refundKey( $orderId, $attempt ) ) ) {
				return;
			}
			$points = abs( (int) $debit['points'] );
			if ( $points <= 0 ) {
				return;
			}
		}

		$debitKey  = self::debitKey( $orderId, $attempt );
		$refundKey = self::refundKey( $orderId, $attempt );
		$refunded  = HWE_Loyalty_Ledger::reverseDebit( $debitKey, $refundKey );

		// `null` puede ser «ya devuelto» (la clave existe) o «no existe el cargo». Solo el
		// segundo caso es un problema real; el primero es la idempotencia esperada.
		if ( null === $refunded && null === self::entryWithKey( $refundKey ) ) {
			self::log( 'no se pudo devolver el canje del pedido ' . $orderId . ': no existe el cargo.' );
			return;
		}

		$order->update_meta_data( self::META_REDEEMED_POINTS, 0 );

		// Solo un pedido `failed` que nunca se cobró se reintenta (`needs_payment()` acepta
		// pending/failed; un `cancelled` no vuelve): sin puntos, tampoco puede conservar el
		// descuento. No se usa `is_paid()`, que mira el estado ACTUAL: un pedido cobrado y luego
		// cancelado o reembolsado ya no «está pagado», y quitarle el fee le subía el total.
		$paidAt = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
		if ( 'failed' === $status && empty( $paidAt ) ) {
			$order->delete_meta_data( HWE_Loyalty_Earnings::META_DISCOUNT );
			$order->delete_meta_data( HWE_Loyalty_Earnings::META_PAID_WITH_POINTS );
			self::syncOrderDiscount( $order, 0, 0.0, HWE_Loyalty_Settings::get() );
			self::forgetCopiedFees( $order );
		}

		$order->save_meta_data();
		self::noteRefund( $order, $points, $status, HWE_Loyalty_Settings::get() );
	}

	// ------------------------------------------------------------------------
	//  Lógica pura del canje
	// ------------------------------------------------------------------------

	/**
	 * Calcula el estado del canje sin tocar WordPress. Es la única implementación de los
	 * recortes y topes: la usan el fee, el estado de la extensión y la confirmación del pedido.
	 *
	 * @param array<string,mixed> $input  `items_total`, `shipping_total`, `fees_total`,
	 *                                    `balance`, `requested`, `logged_in`, `participant`, `active`.
	 * @param array<string,mixed> $settings Ajustes saneados de `HWE_Loyalty_Settings::get()`.
	 * @return array<string,mixed>
	 */
	public static function quote( array $input, array $settings ): array {
		$items       = max( 0.0, (float) ( $input['items_total'] ?? 0.0 ) );
		$shipping    = max( 0.0, (float) ( $input['shipping_total'] ?? 0.0 ) );
		$fees        = (float) ( $input['fees_total'] ?? 0.0 );
		$balance     = max( 0, (int) ( $input['balance'] ?? 0 ) );
		$requested   = max( 0, (int) ( $input['requested'] ?? 0 ) );
		$loggedIn    = ! empty( $input['logged_in'] );
		$participant = ! empty( $input['participant'] );
		$active      = ! empty( $input['active'] );
		$value       = (float) ( $settings['point_value'] ?? 0.0 );
		$minRedeem   = max( 0, (int) ( $settings['min_redeem'] ?? 0 ) );
		$maxPerOrder = max( 0, (int) ( $settings['max_redeem_per_order'] ?? 0 ) );
		$maxPercent  = min( 100, max( 0, (int) ( $settings['max_redeem_percent'] ?? 0 ) ) );
		$full        = ! empty( $settings['full_payment_enabled'] );
		$minCash     = max( 0.0, (float) ( $settings['min_cash_due'] ?? 0.0 ) );

		$total  = max( 0.0, $items + $shipping + $fees );
		$state  = array(
			'available'              => false,
			'reason'                 => null,
			'balance'                => 0,
			'points_applied'         => 0,
			'discount'               => 0.0,
			'max_redeemable'         => 0,
			'min_redeem'             => $minRedeem,
			'full_payment_available' => false,
		);

		if ( ! $active || $value <= 0.0 ) {
			$state['reason'] = 'disabled';
			return $state;
		}
		if ( ! $loggedIn ) {
			$state['reason'] = 'guest';
			return $state;
		}
		if ( ! $participant ) {
			$state['reason'] = 'not_participant';
			return $state;
		}

		$state['balance'] = $balance;

		if ( $full ) {
			$maxByCash = (int) floor( $total / $value + 1e-9 );
		} else {
			if ( $total < $minCash ) {
				$state['reason'] = 'cart_below_cash_minimum';
				return $state;
			}
			$nonShipping = max( 0.0, $items + $fees );
			$maxDiscount = min( $nonShipping, max( 0.0, $total - $minCash ) );
			$maxByCash   = (int) floor( $maxDiscount / $value + 1e-9 );

			if ( $maxDiscount <= 0.0 || $maxByCash <= 0 ) {
				$state['reason'] = 'cart_below_cash_minimum';
				return $state;
			}
		}

		$maxRedeemable = min( $balance, $maxByCash );
		if ( $maxPerOrder > 0 ) {
			$maxRedeemable = min( $maxRedeemable, $maxPerOrder );
		}
		if ( $maxPercent > 0 ) {
			// La base del porcentaje es SIEMPRE productos + cargos ajenos: los tres
			// llamadores reparten el importe entre `items_total` y `fees_total` de forma
			// distinta (el carrito los separa; el estado y el pedido los juntan en items),
			// y el envío nunca entra en la base.
			$percentBase  = max( 0.0, $items + $fees );
			$maxByPercent = (int) floor( $percentBase * $maxPercent / 100.0 / $value + 1e-9 );
			$maxRedeemable = min( $maxRedeemable, $maxByPercent );
		}
		$maxRedeemable = self::filterMaxRedeemable(
			max( 0, $maxRedeemable ),
			array(
				'items_total'    => $items,
				'shipping_total' => $shipping,
				'fees_total'     => $fees,
				'balance'        => $balance,
				'settings'       => $settings,
			)
		);
		$state['max_redeemable'] = $maxRedeemable;

		if ( $maxRedeemable < $minRedeem || $maxRedeemable <= 0 ) {
			$state['reason'] = 'below_minimum';
			return $state;
		}

		$state['available'] = true;
		$pointsToCover      = self::pointsToCover( $total, $value );
		$state['full_payment_available'] = $full
			&& $total > 0.0
			&& $maxRedeemable >= $pointsToCover;

		if ( $requested <= 0 ) {
			return $state;
		}
		if ( $requested < $minRedeem ) {
			$state['reason'] = 'below_minimum';
			return $state;
		}

		$points = min( $requested, $maxRedeemable );
		if ( $points <= 0 ) {
			$state['reason'] = 'below_minimum';
			return $state;
		}

		$raw = self::money( $points * $value );

		if ( $full ) {
			// Al llegar al tope, el fee cubre el total; la diferencia menor que un punto se
			// regala para no cobrar puntos por encima del descuento aplicado.
			$discount = $points >= $maxByCash ? $total : min( $raw, $total );
		} else {
			$discount = min( $raw, $maxDiscount );
		}

		// Nunca se cargan más puntos que el valor del descuento aplicado.
		$pointsByDiscount = (int) floor( ( $discount + 1e-9 ) / $value );
		$points           = min( $points, $pointsByDiscount );

		if ( $points <= 0 ) {
			$state['reason'] = 'below_minimum';
			return $state;
		}

		// El descuento vuelve a corresponder exactamente a los puntos que se cargan; la única
		// excepción es el bonus de la última fracción de punto del pago completo.
		$discount = $full && $points >= $maxByCash ? $total : self::money( $points * $value );

		if ( $discount <= 0.0 ) {
			$state['reason'] = 'below_minimum';
			return $state;
		}

		$state['points_applied'] = $points;
		$state['discount']       = $discount;

		return $state;
	}

	/**
	 * Puntos que cubren el total (redondeando hacia arriba conceptualmente, aunque el bonus de
	 * la última fracción de punto se calcula con `floor(total / value)`).
	 */
	private static function pointsToCover( float $total, float $value ): int {
		if ( $value <= 0.0 || $total <= 0.0 ) {
			return 0;
		}
		return (int) floor( $total / $value + 1e-9 );
	}

	/**
	 * Aplica `hwe_loyalty_max_redeemable` sobre el máximo ya calculado. **Solo puede
	 * endurecer:** un valor mayor que el calculado, negativo o que no sea un entero se
	 * ignora y se registra. `0` sí se acepta y cierra el canje.
	 *
	 * @param array<string,mixed> $context `items_total`, `shipping_total`, `fees_total`,
	 *                                     `balance` y `settings`.
	 */
	private static function filterMaxRedeemable( int $max, array $context ): int {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $max;
		}

		try {
			$filtered = apply_filters( self::FILTER_MAX_REDEEMABLE, $max, $context );
		} catch ( \Throwable $e ) {
			self::log(
				'el filtro ' . self::FILTER_MAX_REDEEMABLE
				. ' lanzó una excepción; se usa el máximo calculado.'
			);
			return $max;
		}

		if ( ! is_scalar( $filtered ) || ! preg_match( '/^-?\d+$/', trim( (string) $filtered ) ) ) {
			self::log(
				'el filtro ' . self::FILTER_MAX_REDEEMABLE
				. ' devolvió un valor no entero; se ignora.'
			);
			return $max;
		}

		$value = (int) $filtered;
		if ( $value < 0 ) {
			self::log(
				'el filtro ' . self::FILTER_MAX_REDEEMABLE
				. ' devolvió un valor negativo; se ignora.'
			);
			return $max;
		}
		if ( $value > $max ) {
			self::log(
				'el filtro ' . self::FILTER_MAX_REDEEMABLE . ' intentó ampliar el máximo de '
				. $max . ' a ' . $value . '; se ignora.'
			);
			return $max;
		}

		return $value;
	}

	// ------------------------------------------------------------------------
	//  Metas, notas y sincronización del pedido
	// ------------------------------------------------------------------------

	/**
	 * Escribe el descuento del canje en el pedido y registra el intento. Si el total quedó en
	 * 0 por puntos, marca `_hwe_loyalty_paid_with_points`. La nota privada solo se añade la
	 * primera vez (o al recuperar un cargo cuyas metas no se guardaron).
	 */
	private static function writeOrderMetas( mixed $order, int $points, float $discount, int $attempt, array $settings, bool $note = true ): void {
		if ( ! method_exists( $order, 'update_meta_data' ) || ! method_exists( $order, 'save_meta_data' ) ) {
			return;
		}

		$order->update_meta_data( self::META_REDEEMED_POINTS, $points );
		$order->update_meta_data( HWE_Loyalty_Earnings::META_DISCOUNT, self::moneyString( $discount ) );
		$order->update_meta_data( self::META_REDEEM_ATTEMPT, $attempt );

		if ( $points > 0 && (float) $order->get_total() <= 0.00001 ) {
			$order->update_meta_data( HWE_Loyalty_Earnings::META_PAID_WITH_POINTS, 'yes' );
		} elseif ( method_exists( $order, 'delete_meta_data' ) ) {
			$order->delete_meta_data( HWE_Loyalty_Earnings::META_PAID_WITH_POINTS );
		}

		$order->save_meta_data();
		if ( $note ) {
			self::noteRedemption( $order, $points, $discount, $settings );
		}
	}

	/**
	 * Sincroniza las líneas de fee del pedido con el descuento respaldado. Quita cualquier fee
	 * de fidelización (incluido el que copió la Store API del carrito) y, si hay canje, añade
	 * uno negativo no imponible. Recalcula los totales para que el cobro use ese importe.
	 */
	private static function syncOrderDiscount( mixed $order, int $points, float $discount, array $settings ): void {
		if ( ! method_exists( $order, 'get_fees' ) || ! method_exists( $order, 'remove_item' ) ) {
			return;
		}

		foreach ( (array) $order->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || ! method_exists( $fee, 'get_name' ) || ! method_exists( $fee, 'get_id' ) ) {
				continue;
			}
			if ( self::isLoyaltyFeeName( (string) $fee->get_name() ) ) {
				$order->remove_item( (int) $fee->get_id() );
			}
		}

		if ( $points > 0 && $discount > 0.0 && class_exists( 'WC_Order_Item_Fee' ) && method_exists( $order, 'add_item' ) ) {
			$item = new \WC_Order_Item_Fee();
			$item->set_name( self::feeName( $points, $settings ) );
			$item->set_total( -1.0 * $discount );
			if ( method_exists( $item, 'set_amount' ) ) {
				$item->set_amount( -1.0 * $discount );
			}
			if ( method_exists( $item, 'set_tax_status' ) ) {
				$item->set_tax_status( 'none' );
			}
			$order->add_item( $item );
		}

		if ( method_exists( $order, 'calculate_totals' ) ) {
			$order->calculate_totals( false );
		} elseif ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/** Quita el descuento de un pedido que no debe tener canje (ajustes apagados, fallo). */
	private static function removeOrderDiscount( mixed $order, array $settings ): void {
		if ( ! self::hasLoyaltyFee( $order ) ) {
			return;
		}

		self::syncOrderDiscount( $order, 0, 0.0, $settings );
		self::forgetCopiedFees( $order );
	}

	/** ¿El pedido tiene alguna línea de fee de fidelización (aunque sea de importe 0)? */
	private static function hasLoyaltyFee( mixed $order ): bool {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_fees' ) ) {
			return false;
		}

		foreach ( (array) $order->get_fees() as $fee ) {
			if ( is_object( $fee ) && method_exists( $fee, 'get_name' ) && self::isLoyaltyFeeName( (string) $fee->get_name() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Borra el hash de fees copiados por la Store API para que un reintento del borrador vuelva
	 * a crearlos desde el carrito. Sin esto, el pedido conservaría la lista de fees vieja (sin
	 * el descuento) y el canje no podría reconfirmarse.
	 */
	private static function forgetCopiedFees( mixed $order ): void {
		if ( ! method_exists( $order, 'delete_meta_data' ) ) {
			return;
		}

		$order->delete_meta_data( self::META_FEES_HASH );
		if ( method_exists( $order, 'save_meta_data' ) ) {
			$order->save_meta_data();
		}
	}

	/** Suma de los fees de fidelización del pedido, en positivo. */
	private static function orderLoyaltyDiscount( mixed $order ): float {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_fees' ) ) {
			return 0.0;
		}

		$discount = 0.0;
		foreach ( (array) $order->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || ! method_exists( $fee, 'get_name' ) || ! method_exists( $fee, 'get_total' ) ) {
				continue;
			}
			if ( ! self::isLoyaltyFeeName( (string) $fee->get_name() ) ) {
				continue;
			}
			$discount += -1.0 * (float) $fee->get_total();
		}

		return max( 0.0, $discount );
	}

	/** Nota privada con la trazabilidad del canje; la unidad sale de los ajustes. */
	private static function noteRedemption( mixed $order, int $points, float $discount, array $settings ): void {
		if ( ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				'Fidelización: el pedido canjeó %d %s por un descuento de %s.',
				$points,
				self::unitName( $points, $settings ),
				self::moneyLabel( $discount )
			),
			false
		);
	}

	/** Nota privada de la devolución; nunca escribe el nombre de la unidad a mano. */
	private static function noteRefund( mixed $order, int $points, string $status, array $settings ): void {
		if ( ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}

		$reasons = array(
			'cancelled' => 'la cancelación',
			'failed'    => 'el fallo del pago',
			'refunded'  => 'el reembolso',
		);
		$reason  = $reasons[ $status ] ?? 'la devolución';

		$order->add_order_note(
			sprintf(
				'Fidelización: se devolvieron %d %s por %s.',
				$points,
				self::unitName( $points, $settings ),
				$reason
			),
			false
		);
	}

	// ------------------------------------------------------------------------
	//  Claves e idempotencia
	// ------------------------------------------------------------------------

	/** Clave canónica del primer intento; los reintentos tras una devolución llevan sufijo. */
	private static function debitKey( int $orderId, int $attempt ): string {
		return 'redeem:order:' . $orderId . ( $attempt > 1 ? ':' . $attempt : '' );
	}

	private static function refundKey( int $orderId, int $attempt ): string {
		return 'redeem_refund:order:' . $orderId . ( $attempt > 1 ? ':' . $attempt : '' );
	}

	/**
	 * Intento que toca: el primero usa la clave canónica; si el último cargo ya se devolvió,
	 * usa el siguiente sufijo; si el último cargo sigue vivo (metas perdidas), lo reutiliza
	 * para que la recuperación no cargue dos veces.
	 */
	private static function nextAttempt( mixed $order, int $orderId ): int {
		$last = method_exists( $order, 'get_meta' ) ? (int) $order->get_meta( self::META_REDEEM_ATTEMPT ) : 0;
		if ( $last <= 0 ) {
			return 1;
		}

		$debit = self::entryWithKey( self::debitKey( $orderId, $last ) );
		if ( null === $debit ) {
			return 1;
		}
		if ( null === self::entryWithKey( self::refundKey( $orderId, $last ) ) ) {
			return $last;
		}

		return $last + 1;
	}

	/** Intento ya confirmado del pedido, para reescribir sus metas. */
	private static function currentAttempt( mixed $order ): int {
		$attempt = method_exists( $order, 'get_meta' ) ? (int) $order->get_meta( self::META_REDEEM_ATTEMPT ) : 0;

		return $attempt > 0 ? $attempt : 1;
	}

	/** ¿El pedido está reembolsado del todo? Sin registros de reembolso, un cambio manual cuenta. */
	private static function isFullRefund( mixed $order ): bool {
		$total    = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
		$refunded = method_exists( $order, 'get_total_refunded' ) ? (float) $order->get_total_refunded() : 0.0;

		if ( $total <= 0.0 ) {
			return true;
		}

		return $refunded <= 0.0 || $refunded + 0.00001 >= $total;
	}

	/**
	 * Fila del libro con esa clave, o `null`. Se usa para distinguir «clave repetida» de
	 * «saldo insuficiente» cuando `debit()` devuelve `null`, y para la idempotencia de la
	 * devolución.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function entryWithKey( string $key ): ?array {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! class_exists( 'HWE_Loyalty_Schema' ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE idempotency_key = %s LIMIT 1',
				$key
			)
		);

		return is_object( $row ) ? (array) $row : null;
	}

	// ------------------------------------------------------------------------
	//  Lectura del carrito, sesión y ajustes
	// ------------------------------------------------------------------------

	/** Puntos pedidos por el cliente (0 si no hay sesión, programa apagado o valor inválido). */
	/** Puntos que el cliente vio aplicados en su último carrito (ver `SHOWN_SESSION_KEY`). */
	private static function shownPoints(): int {
		$session = self::session();
		if ( null === $session ) {
			return 0;
		}

		return self::normalizePoints( $session->get( self::SHOWN_SESSION_KEY, 0 ) ) ?? 0;
	}

	/**
	 * Canje confirmado: la petición se vacía para que la siguiente compra no gaste puntos que
	 * el cliente no ha pedido para ella.
	 */
	private static function forgetRequest(): void {
		$session = self::session();
		if ( null === $session ) {
			return;
		}

		$session->set( self::SESSION_KEY, 0 );
		$session->set( self::SHOWN_SESSION_KEY, 0 );
	}

	private static function forgetShown(): void {
		$session = self::session();
		if ( null !== $session ) {
			$session->set( self::SHOWN_SESSION_KEY, 0 );
		}
	}

	public static function requestedPoints(): int {
		if ( ! HWE_Loyalty_Settings::pointsActive() ) {
			return 0;
		}

		$session = self::session();
		if ( null === $session ) {
			return 0;
		}

		return self::normalizePoints( $session->get( self::SESSION_KEY, 0 ) ) ?? 0;
	}

	/**
	 * Normaliza la cantidad pedida: entero ≥ 0 (acepta el número JSON y su forma decimal
	 * entera). Cualquier otra cosa (negativos, decimales, texto, arrays) es `null`.
	 */
	public static function normalizePoints( mixed $raw ): ?int {
		if ( is_bool( $raw ) || is_array( $raw ) || is_object( $raw ) ) {
			return null;
		}

		$value = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );

		return false === $value ? null : (int) $value;
	}

	/**
	 * Total de fees ajenos al canje añadidos al carrito antes que el nuestro. Se usa solo
	 * para el tope del carrito; sus impuestos se conocen después, así que se suman si ya
	 * están calculados.
	 */
	private static function otherFeesTotal( mixed $cart ): float {
		$total = 0.0;
		foreach ( (array) $cart->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || ! isset( $fee->name ) || self::isLoyaltyFeeName( (string) $fee->name ) ) {
				continue;
			}
			$total += (float) ( $fee->amount ?? 0.0 );
			if ( isset( $fee->tax ) && is_numeric( $fee->tax ) ) {
				$total += (float) $fee->tax;
			}
		}

		return $total;
	}

	/** Descuento del fee de fidelización ya calculado en el carrito, en positivo. */
	private static function cartLoyaltyDiscount( mixed $cart ): float {
		$discount = 0.0;
		foreach ( (array) $cart->get_fees() as $fee ) {
			if ( ! is_object( $fee ) || ! isset( $fee->name ) || ! self::isLoyaltyFeeName( (string) $fee->name ) ) {
				continue;
			}
			$amount = isset( $fee->total ) && is_numeric( $fee->total )
				? (float) $fee->total
				: (float) ( $fee->amount ?? 0.0 );
			$discount += -1.0 * min( 0.0, $amount );
		}

		return max( 0.0, $discount );
	}

	/**
	 * Puntos que corresponden a un descuento con el valor actual. Se usa como respaldo cuando
	 * el pedido ya trae un fee del carrito pero la sesión perdió la cantidad pedida.
	 */
	private static function pointsForDiscount( float $discount, float $value ): int {
		if ( $discount <= 0.0 || $value <= 0.0 ) {
			return 0;
		}

		return (int) floor( $discount / $value + 1e-9 );
	}

	/** Nombre visible del fee: el importe de puntos y la unidad de los ajustes. */
	private static function feeName( int $points, array $settings ): string {
		return self::FEE_PREFIX . $points . ' ' . self::unitName( $points, $settings );
	}

	private static function isLoyaltyFeeName( string $name ): bool {
		return str_starts_with( trim( $name ), self::FEE_PREFIX );
	}

	/** Singular/plural desde los ajustes, nunca escrito a mano en el texto. */
	private static function unitName( int $points, array $settings ): string {
		$key = 1 === $points ? 'currency_name' : 'currency_name_plural';
		$raw = $settings[ $key ] ?? '';

		if ( is_scalar( $raw ) && '' !== trim( (string) $raw ) ) {
			return trim( (string) $raw );
		}

		return (string) HWE_Loyalty_Settings::DEFAULTS['currency_name_plural'];
	}

	/** Precisión de la moneda de WooCommerce (2 si no está disponible). */
	private static function decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	private static function money( float $amount ): float {
		return round( $amount, self::decimals() );
	}

	private static function moneyString( float $amount ): string {
		return function_exists( 'wc_format_decimal' )
			? (string) wc_format_decimal( $amount, self::decimals() )
			: number_format( $amount, self::decimals(), '.', '' );
	}

	private static function moneyLabel( float $amount ): string {
		$number   = function_exists( 'number_format_i18n' )
			? number_format_i18n( $amount, self::decimals() )
			: number_format( $amount, self::decimals(), '.', '' );
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

		return '' !== $currency ? $number . ' ' . $currency : $number;
	}

	/** Importe en unidades menores, como string (convención de la Store API). */
	private static function toMinorUnits( float $amount ): string {
		$factor = 10 ** self::decimals();

		return (string) (int) round( $amount * $factor );
	}

	/** @return array<string,mixed> */
	private static function emptyState( array $settings ): array {
		return array(
			'available'              => false,
			'reason'                 => 'disabled',
			'balance'                => 0,
			'points_applied'         => 0,
			'discount'               => '0',
			'max_redeemable'         => 0,
			'min_redeem'             => max( 0, (int) ( $settings['min_redeem'] ?? 0 ) ),
			'full_payment_available' => false,
		);
	}

	/** @param array<string,mixed> $data */
	private static function decodeData( mixed $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private static function currentUserId(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/** Carrito de la sesión actual, o `null` fuera de una petición de WooCommerce. */
	private static function cart(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();

		return is_object( $wc ) && isset( $wc->cart ) && is_object( $wc->cart ) ? $wc->cart : null;
	}

	/** Sesión de WooCommerce, o `null` fuera de una petición con sesión. */
	private static function session(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
			return null;
		}
		if ( ! method_exists( $wc->session, 'get' ) || ! method_exists( $wc->session, 'set' ) ) {
			return null;
		}

		return $wc->session;
	}

	/** Error de la Store API (o `RuntimeException` si WooCommerce no está cargado). */
	private static function error( string $code, string $message ): \Throwable {
		$class = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( class_exists( $class ) ) {
			return new $class( $code, $message, 400 );
		}

		return new \RuntimeException( $message );
	}

	private static function insufficientPoints( array $settings ): \Throwable {
		$unit = self::unitName( 0, $settings );

		return self::error(
			self::ERROR_INSUFFICIENT,
			sprintf(
				'No hay suficientes %s para confirmar el canje de este pedido. Revisa el carrito e inténtalo de nuevo.',
				$unit
			)
		);
	}

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: ' . $message );
	}
}
