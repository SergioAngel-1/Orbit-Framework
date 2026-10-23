<?php
/**
 * Ganancias por pedido y su reversión (spec §6.1, §6.2, §6.4 y §5.5).
 *
 * **Cuándo cuenta un pedido** (§6.1): solo cuando está cobrado. Se escucha en WordPress (la
 * fuente de verdad), no en el BFF: transición a `processing` o `completed` con `is_paid()`
 * verdadero, o `payment_complete`. Los medios offline que todavía no han cobrado (por defecto
 * `cod`) esperan a `completed`; el criterio vive en el filtro
 * `hwe_loyalty_collected_on_completion`.
 *
 * **Base de cálculo** (`orderBase()`): la suma de los totales de línea (después de cupones) más
 * sus impuestos, es decir, lo que el cliente pagó por los productos. No se usa «total − envío −
 * fees» porque los extras de envío del módulo `shipping` llegan como `fee_lines` sin ninguna
 * marca y no se pueden distinguir de otros fees; por eso se ignoran TODAS las líneas de fee. De
 * esa base se resta la parte pagada con puntos (meta `_hwe_loyalty_discount`, escrita por la
 * tarea de canje) y el resultado nunca baja de 0. Los puntos se redondean siempre hacia abajo.
 *
 * **Conceptos** (§6.2): cashback al comprador (si está activo y participa); comisión N1 al
 * referidor (porcentaje de primera compra o siguientes, dentro de `commission_duration_days`
 * desde la confirmación de la atribución —o desde su atribución si todavía está pendiente—);
 * comisión N2 al referidor del referidor (solo con `level2_enabled`, mismas reglas); y bono por
 * producto al N1 (`bono × cantidad` por línea). El bono no está limitado por la duración de la
 * comisión: premia un producto, no una ventana temporal (decisión propia, documentada).
 *
 * **Idempotencia** (Foco de revisión 2): cada abono lleva una clave única por pedido, concepto y
 * beneficiario (`earn:order:<id>:<tipo>:<usuario>`), así que `processing` → `completed` →
 * `payment_complete`, un webhook repetido o una reentrada no duplican puntos. Además, la meta
 * `_hwe_loyalty_earnings` marca el pedido como procesado y guarda qué se abonó a quién. Una
 * privada por abono deja la trazabilidad para el equipo, con el nombre de la unidad de los
 * ajustes (nunca escrito a mano).
 *
 * **Reversión** (§6.4): si un pedido que ya generó ganancias pasa a `cancelled` o a `refunded`
 * **total**, se genera un `earn_reversal` por beneficiario limitado a su saldo (nunca deja el
 * saldo negativo). Lo no recuperado se anota en el pedido. Un reembolso parcial no revierte.
 * Revertir dos veces no duplica el cargo.
 *
 * **Convención de referencia** (tarea 5): las comisiones y el bono llevan
 * `ref = ['type' => 'user', 'id' => <comprador>]` para que `HWE_Loyalty_Referrals::network()`
 * sume los puntos generados por cada referido; el cashback lleva
 * `ref = ['type' => 'order', 'id' => <pedido>]`. El número de pedido va también en `data`.
 *
 * No hay canje aquí (tarea 8) ni eventos de reseña/cumpleaños (tarea 7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Earnings {

	/** Estado del procesamiento de ganancias del pedido (lo lee también la caja de wp-admin). */
	public const META_EARNINGS = '_hwe_loyalty_earnings';

	/** Parte del pedido pagada con puntos (la escribe la tarea de canje). */
	public const META_DISCOUNT = '_hwe_loyalty_discount';

	/** Marca de pedido pagado íntegramente con puntos (la escribe la tarea de canje). */
	public const META_PAID_WITH_POINTS = '_hwe_loyalty_paid_with_points';

	public const STATUS_PROCESSED = 'processed';
	public const STATUS_REVERSED  = 'reversed';
	public const STATUS_SKIPPED   = 'skipped';

	/** Estados de WooCommerce que pueden considerarse cobrados. */
	private const COLLECTED_STATUSES = array( 'processing', 'completed' );

	/** Estados en los que un pedido dejó de existir para ganar puntos. */
	private const REVERSAL_STATUSES = array( 'cancelled', 'refunded' );

	/** Cuántos pedidos anteriores se miran para decidir si es la primera compra cobrada. */
	private const PRIOR_ORDER_LIMIT = 20;

	/** Engancha los eventos de estado de WooCommerce. Lo llama `HWE_Loyalty::boot()`. */
	public static function register(): void {
		add_action( 'woocommerce_order_status_processing', array( self::class, 'onOrderProcessing' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'onOrderCompleted' ) );
		add_action( 'woocommerce_payment_complete', array( self::class, 'onPaymentComplete' ) );
		// Prioridad 20: después de que `HWE_Loyalty_Redemption` (10) devuelva los puntos canjeados
		// en el mismo pedido. Al revés, la reversión vería el saldo sin ellos, no recuperaría nada
		// y el cliente se quedaría con puntos sin respaldo si ya gastó las ganancias del pedido.
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'onOrderCancelled' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'onOrderRefunded' ), 20 );
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

	/**
	 * Solo un reembolso TOTAL revierte (spec §6.4). Un cambio manual a `refunded` sin registros
	 * de reembolso se considera total; uno con reembolsos por menos del total, parcial.
	 */
	public static function onOrderRefunded( int $orderId ): void {
		$order = self::order( $orderId );
		if ( null === $order ) {
			self::log( 'no se pudo cargar el pedido ' . $orderId . ' para revertir sus ganancias.' );
			return;
		}

		if ( ! self::isFullRefund( $order ) ) {
			self::log( 'el pedido ' . $orderId . ' tiene un reembolso parcial; no se revierten sus ganancias.' );
			return;
		}

		self::reverseOrder( $orderId );
	}

	// ------------------------------------------------------------------------
	//  Procesamiento de ganancias
	// ------------------------------------------------------------------------

	/**
	 * Abona las ganancias del pedido si está cobrado. Idempotente por pedido: un pedido ya
	 * procesado (o revertido) no vuelve a abonar ni a escribir notas.
	 */
	public static function processOrder( int $orderId ): void {
		if ( ! HWE_Loyalty_Settings::pointsActive() ) {
			return;
		}

		$order = self::order( $orderId );
		if ( null === $order ) {
			self::log( 'no se pudo cargar el pedido ' . $orderId . ' para calcular sus ganancias.' );
			return;
		}

		if ( ! self::isCollected( $order ) ) {
			return;
		}

		$state = self::state( $order );
		if ( in_array( (string) ( $state['status'] ?? '' ), array( self::STATUS_PROCESSED, self::STATUS_REVERSED, self::STATUS_SKIPPED ), true ) ) {
			return;
		}

		if ( self::paidWithPoints( $order ) ) {
			self::writeState(
				$order,
				array(
					'version' => 1,
					'status'  => self::STATUS_SKIPPED,
					'reason'  => 'paid_with_points',
					'base'    => 0.0,
					'at'      => self::now(),
				)
			);
			return;
		}

		$buyerId = (int) $order->get_customer_id();
		if ( $buyerId <= 0 ) {
			// Pedido de invitado: no hay cuenta a la que abonar cashback ni referidor al que
			// comisionar. No se marca como procesado: si más adelante se vincula a una cuenta,
			// un nuevo aviso de estado podrá generar sus ganancias.
			return;
		}

		if ( ! HWE_Loyalty_Settings::canParticipate( $buyerId ) ) {
			// La cuenta existe pero su rol no participa: no se abona nada. Tampoco se marca el
			// pedido para que un cambio de rol posterior pueda generar las ganancias.
			return;
		}

		$base     = self::orderBase( $order );
		$settings = HWE_Loyalty_Settings::get();
		$earnings = self::calculate( $order, $buyerId, $base, $settings );

		/** @var mixed $filtered */
		$filtered = apply_filters( 'hwe_loyalty_order_earnings', $earnings, $order, $base, $settings );
		$earnings = self::normalizeEarnings( $filtered );

		$beneficiaries = array();
		foreach ( $earnings as $earning ) {
			$key     = self::earnKey( $orderId, $earning['type'], $earning['user_id'] );
			$entryId = HWE_Loyalty_Ledger::credit(
				$earning['user_id'],
				$earning['points'],
				$earning['type'],
				$key,
				$earning['ref'],
				null,
				$earning['data']
			);

			$beneficiaries[] = array(
				'user_id'  => $earning['user_id'],
				'type'     => $earning['type'],
				'points'   => $earning['points'],
				'key'      => $key,
				'credited' => null !== $entryId,
			);

			if ( null !== $entryId ) {
				self::note( $order, self::creditNote( $earning, $settings, $orderId ) );
			}
		}

		self::writeState(
			$order,
			array(
				'version'       => 1,
				'status'        => self::STATUS_PROCESSED,
				'base'          => $base,
				'beneficiaries' => $beneficiaries,
				'processed_at'  => self::now(),
			)
		);
	}

	// ------------------------------------------------------------------------
	//  Cálculo
	// ------------------------------------------------------------------------

	/**
	 * Base de cálculo: totales de línea (después de cupones) más sus impuestos, menos la parte
	 * pagada con puntos, con suelo 0. Se ignoran el envío y todas las `fee_lines` (los extras de
	 * envío del módulo `shipping` no se pueden distinguir de otros fees).
	 */
	public static function orderBase( WC_Order $order ): float {
		$total = 0.0;

		foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$total += (float) $item->get_total();
			$total += (float) $item->get_total_tax();
		}

		$discount = self::metaFloat( $order, self::META_DISCOUNT );
		$base     = max( 0.0, $total - $discount );
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( $base, max( 0, $decimals ) );
	}

	/**
	 * ¿El pedido está cobrado? `processing` o `completed` con `is_paid()`; los medios del filtro
	 * `hwe_loyalty_collected_on_completion` (por defecto `cod`) solo cuentan en `completed`.
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
		$deferred = apply_filters( 'hwe_loyalty_collected_on_completion', array( 'cod' ) );
		if ( ! is_array( $deferred ) ) {
			self::log( 'el filtro hwe_loyalty_collected_on_completion devolvió ' . get_debug_type( $deferred ) . '; se usa cod.' );
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

	/**
	 * Ganancias calculadas por beneficiario, antes del filtro. Cada entrada:
	 * `user_id`, `type`, `points`, `ref` y `data`.
	 *
	 * @param array<string,mixed> $settings
	 * @return list<array{user_id:int,type:string,points:int,ref:array<string,mixed>,data:array<string,mixed>}>
	 */
	private static function calculate( WC_Order $order, int $buyerId, float $base, array $settings ): array {
		if ( ! HWE_Loyalty_Settings::canParticipate( $buyerId ) ) {
			return array();
		}

		$orderId  = (int) $order->get_id();
		$earnings = array();

		if ( ! empty( $settings['cashback_enabled'] ) && $base > 0 ) {
			$points = (int) floor( $base * (int) $settings['cashback_percent'] / 100 );
			if ( $points > 0 ) {
				$earnings[] = self::earning(
					$buyerId,
					'cashback',
					$points,
					array( 'type' => 'order', 'id' => $orderId ),
					array( 'order' => $orderId )
				);
			}
		}

		if ( ! HWE_Loyalty_Settings::referralsActive() ) {
			return $earnings;
		}

		$referrerId = HWE_Loyalty_Referrals::referrerOf( $buyerId );
		if ( null === $referrerId || ! HWE_Loyalty_Settings::canParticipate( $referrerId ) ) {
			return $earnings;
		}

		// Convención de la red (tarea 5): la comisión se referencia al comprador.
		$ref  = array( 'type' => 'user', 'id' => $buyerId );
		$data = array( 'order' => $orderId );

		$level2Percent = ! empty( $settings['level2_enabled'] ) ? (int) $settings['commission_level2_percent'] : 0;
		$firstPercent  = (int) $settings['commission_first_percent'];
		$nextPercent   = (int) $settings['commission_next_percent'];

		if ( self::withinCommissionWindow( $buyerId, (int) $settings['commission_duration_days'] ) ) {
			if ( $firstPercent > 0 || $nextPercent > 0 || $level2Percent > 0 ) {
				$isFirst = self::isFirstCollectedOrder( $order, $buyerId );

				$l1Percent = $isFirst ? $firstPercent : $nextPercent;
				$l1Points  = $base > 0 ? (int) floor( $base * $l1Percent / 100 ) : 0;
				if ( $l1Points > 0 ) {
					$earnings[] = self::earning( $referrerId, 'referral_commission_l1', $l1Points, $ref, $data );
				}

				if ( $level2Percent > 0 ) {
					$second = HWE_Loyalty_Referrals::referrerOf( $referrerId );
					if ( null !== $second && $second !== $buyerId && HWE_Loyalty_Settings::canParticipate( $second ) ) {
						$l2Points = $base > 0 ? (int) floor( $base * $level2Percent / 100 ) : 0;
						if ( $l2Points > 0 ) {
							$earnings[] = self::earning( $second, 'referral_commission_l2', $l2Points, $ref, $data );
						}
					}
				}
			}
		}

		// Bono por producto: al N1, independiente de la ventana de comisión.
		$bonus = 0;
		foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}
			$quantity  = (int) $item->get_quantity();
			$productId = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			if ( $quantity <= 0 || $productId <= 0 ) {
				continue;
			}
			$bonus += HWE_Loyalty_ProductBonus::bonusFor( $productId ) * $quantity;
		}

		if ( $bonus > 0 ) {
			$earnings[] = self::earning( $referrerId, 'referral_product_bonus', $bonus, $ref, $data );
		}

		return $earnings;
	}

	// ------------------------------------------------------------------------
	//  Reversión
	// ------------------------------------------------------------------------

	/**
	 * Anula las ganancias de un pedido cancelado o reembolsado del todo. Un `earn_reversal` por
	 * beneficiario, limitado a su saldo; lo que no se pudo recuperar queda en la meta y en una
	 * nota privada. Idempotente: un pedido ya revertido no se vuelve a cargar.
	 */
	public static function reverseOrder( int $orderId ): void {
		$order = self::order( $orderId );
		if ( null === $order ) {
			self::log( 'no se pudo cargar el pedido ' . $orderId . ' para revertir sus ganancias.' );
			return;
		}

		$state = self::state( $order );
		if ( self::STATUS_PROCESSED !== (string) ( $state['status'] ?? '' ) ) {
			return;
		}

		$beneficiaries = is_array( $state['beneficiaries'] ?? null ) ? $state['beneficiaries'] : array();
		$settings      = HWE_Loyalty_Settings::get();
		$reversals     = array();
		$unrecovered   = 0;

		foreach ( $beneficiaries as $beneficiary ) {
			if ( ! is_array( $beneficiary ) || empty( $beneficiary['credited'] ) ) {
				continue;
			}

			$userId = (int) ( $beneficiary['user_id'] ?? 0 );
			$points = (int) ( $beneficiary['points'] ?? 0 );
			$type   = is_scalar( $beneficiary['type'] ?? null ) ? (string) $beneficiary['type'] : '';
			if ( $userId <= 0 || $points <= 0 || '' === $type ) {
				continue;
			}

			$balance   = HWE_Loyalty_Ledger::balance( $userId );
			$available = max( 0, (int) ( $balance['balance'] ?? 0 ) );
			$take      = min( $points, $available );
			$recovered = 0;

			if ( $take > 0 ) {
				$entryId = HWE_Loyalty_Ledger::debit(
					$userId,
					$take,
					'earn_reversal',
					self::reverseKey( $orderId, $type, $userId ),
					array( 'type' => 'order', 'id' => $orderId ),
					array( 'order' => $orderId, 'original_type' => $type )
				);
				if ( null !== $entryId ) {
					$recovered = $take;
				}
			}

			$outstanding = max( 0, $points - $recovered );
			$unrecovered += $outstanding;

			$reversals[] = array(
				'user_id'     => $userId,
				'type'        => $type,
				'points'      => $points,
				'recovered'   => $recovered,
				'outstanding' => $outstanding,
			);

			self::note(
				$order,
				self::reversalNote( $userId, $recovered, $outstanding, $settings, $orderId )
			);
		}

		$state['status']      = self::STATUS_REVERSED;
		$state['reversed_at'] = self::now();
		$state['reversals']   = $reversals;
		$state['unrecovered'] = $unrecovered;

		self::writeState( $order, $state );
	}

	// ------------------------------------------------------------------------
	//  Piezas privadas
	// ------------------------------------------------------------------------

	/**
	 * ¿Es la primera compra COBRADA del comprador? Mira sus pedidos en estados pagados, ignorando
	 * el actual, y descarta los que todavía no están cobrados (p. ej. un `cod` en `processing`).
	 */
	private static function isFirstCollectedOrder( WC_Order $order, int $buyerId ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			self::log( 'wc_get_orders no está disponible; se asume primera compra.' );
			return true;
		}

		$statuses = function_exists( 'wc_get_is_paid_statuses' )
			? (array) wc_get_is_paid_statuses()
			: array( 'processing', 'completed' );

		$prior = wc_get_orders(
			array(
				'customer_id' => $buyerId,
				'status'      => $statuses,
				'exclude'     => array( (int) $order->get_id() ),
				'limit'       => self::PRIOR_ORDER_LIMIT,
				'orderby'     => 'date',
				'order'       => 'ASC',
				'return'      => 'objects',
			)
		);

		foreach ( (array) $prior as $candidate ) {
			if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'get_id' ) ) {
				continue;
			}
			if ( (int) $candidate->get_id() === (int) $order->get_id() ) {
				continue;
			}
			if ( self::isCollected( $candidate ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * ¿Está la compra dentro de la duración de la comisión? Se cuenta desde la confirmación de
	 * la atribución y, si todavía está pendiente, desde su atribución. `0` = sin límite.
	 */
	private static function withinCommissionWindow( int $buyerId, int $days ): bool {
		if ( $days <= 0 ) {
			return true;
		}

		$attribution = HWE_Loyalty_Referrals::attributionOf( $buyerId );
		if ( null === $attribution ) {
			self::log( 'el usuario ' . $buyerId . ' no tiene atribución; no se paga comisión con duración.' );
			return false;
		}

		$date = (string) ( $attribution['confirmed_at'] ?? '' );
		if ( '' === $date ) {
			$date = (string) ( $attribution['attributed_at'] ?? '' );
		}
		if ( '' === $date ) {
			self::log( 'la atribución del usuario ' . $buyerId . ' no tiene fecha; no se paga comisión con duración.' );
			return false;
		}

		$timestamp = strtotime( $date . ' UTC' );
		if ( false === $timestamp ) {
			self::log( 'la fecha de atribución del usuario ' . $buyerId . ' no es válida.' );
			return false;
		}

		return time() <= $timestamp + $days * DAY_IN_SECONDS;
	}

	/** ¿El pedido se pagó íntegramente con puntos? Entonces no genera ganancias (spec §6.1). */
	private static function paidWithPoints( WC_Order $order ): bool {
		$raw = $order->get_meta( self::META_PAID_WITH_POINTS );
		if ( ! is_scalar( $raw ) ) {
			return false;
		}

		return in_array( strtolower( trim( (string) $raw ) ), array( 'yes', '1', 'true' ), true );
	}

	/** Un reembolso total revierte; uno parcial (con importe registrado menor que el total) no. */
	private static function isFullRefund( WC_Order $order ): bool {
		$refunded = (float) $order->get_total_refunded();
		if ( $refunded <= 0 ) {
			// Cambio manual de estado a `refunded` sin filas de reembolso: se trata como total.
			return true;
		}

		return $refunded + 0.01 >= (float) $order->get_total();
	}

	/**
	 * Saneado del filtro `hwe_loyalty_order_earnings`: descarta lo que no sea un abono válido a
	 * un usuario existente y agrupa las entradas repetidas del mismo tipo y usuario.
	 *
	 * @return list<array{user_id:int,type:string,points:int,ref:array<string,mixed>,data:array<string,mixed>}>
	 */
	private static function normalizeEarnings( mixed $filtered ): array {
		if ( ! is_array( $filtered ) ) {
			self::log( 'el filtro hwe_loyalty_order_earnings devolvió ' . get_debug_type( $filtered ) . '; se descarta.' );
			return array();
		}

		$normalized = array();
		foreach ( $filtered as $entry ) {
			if ( ! is_array( $entry ) ) {
				self::log( 'se descarta una entrada que no es un mapa del filtro hwe_loyalty_order_earnings.' );
				continue;
			}

			$userId    = (int) ( $entry['user_id'] ?? 0 );
			$type      = is_scalar( $entry['type'] ?? null ) ? trim( (string) $entry['type'] ) : '';
			$rawPoints = $entry['points'] ?? 0;
			$points    = is_numeric( $rawPoints ) ? (int) floor( (float) $rawPoints ) : 0;

			if ( $userId <= 0 || $points <= 0 || '' === $type ) {
				self::log( 'se descarta una entrada incompleta del filtro hwe_loyalty_order_earnings.' );
				continue;
			}
			if ( ! HWE_Loyalty_EntryTypes::isCredit( $type ) ) {
				self::log( 'se descarta el abono «' . preg_replace( '/[^a-z0-9_\-]/i', '', $type ) . '»: no es un tipo de abono.' );
				continue;
			}
			if ( function_exists( 'get_userdata' ) && false === get_userdata( $userId ) ) {
				self::log( 'se descarta un abono al usuario ' . $userId . ': no existe.' );
				continue;
			}

			$ref  = is_array( $entry['ref'] ?? null ) ? $entry['ref'] : array();
			$data = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();
			$slot = $type . ':' . $userId;

			if ( isset( $normalized[ $slot ] ) ) {
				$normalized[ $slot ]['points'] += $points;
				continue;
			}

			$normalized[ $slot ] = array(
				'user_id' => $userId,
				'type'    => $type,
				'points'  => $points,
				'ref'     => $ref,
				'data'    => $data,
			);
		}

		return array_values( $normalized );
	}

	/**
	 * @param array<string,mixed> $ref
	 * @param array<string,mixed> $data
	 * @return array{user_id:int,type:string,points:int,ref:array<string,mixed>,data:array<string,mixed>}
	 */
	private static function earning( int $userId, string $type, int $points, array $ref, array $data ): array {
		return array(
			'user_id' => $userId,
			'type'    => $type,
			'points'  => $points,
			'ref'     => $ref,
			'data'    => $data,
		);
	}

	/**
	 * Nota privada de un abono. La unidad sale de los ajustes (`currency_name_plural`), nunca de
	 * un texto escrito a mano; solo lleva ids, sin datos personales.
	 *
	 * @param array{user_id:int,type:string,points:int} $earning
	 * @param array<string,mixed>                      $settings
	 */
	private static function creditNote( array $earning, array $settings, int $orderId ): string {
		return sprintf(
			'Fidelización: %d %s al usuario #%d por %s (pedido #%d).',
			(int) $earning['points'],
			self::unitName( $settings ),
			(int) $earning['user_id'],
			self::label( (string) $earning['type'] ),
			$orderId
		);
	}

	/** Nota privada de una anulación, con lo que no se pudo recuperar. */
	private static function reversalNote( int $userId, int $recovered, int $outstanding, array $settings, int $orderId ): string {
		$unit = self::unitName( $settings );

		$note = sprintf(
			'Fidelización: anulados %d %s al usuario #%d por el pedido #%d',
			$recovered,
			$unit,
			$userId,
			$orderId
		);

		if ( $outstanding > 0 ) {
			$note .= sprintf( '; no recuperado: %d %s', $outstanding, $unit );
		}

		return $note . '.';
	}

	/** @param array<string,mixed> $settings */
	private static function unitName( array $settings ): string {
		$unit = $settings['currency_name_plural'] ?? '';

		return is_scalar( $unit ) && '' !== trim( (string) $unit )
			? trim( (string) $unit )
			: (string) HWE_Loyalty_Settings::DEFAULTS['currency_name_plural'];
	}

	private static function label( string $type ): string {
		return match ( $type ) {
			'cashback'                  => 'devolución de la compra',
			'referral_commission_l1'    => 'comisión de referido (nivel 1)',
			'referral_commission_l2'    => 'comisión de referido (nivel 2)',
			'referral_product_bonus'    => 'bono por producto',
			default                     => 'ganancia',
		};
	}

	private static function earnKey( int $orderId, string $type, int $userId ): string {
		return 'earn:order:' . $orderId . ':' . $type . ':' . $userId;
	}

	private static function reverseKey( int $orderId, string $type, int $userId ): string {
		return 'reverse:order:' . $orderId . ':' . $type . ':' . $userId;
	}

	private static function note( WC_Order $order, string $message ): void {
		if ( ! method_exists( $order, 'add_order_note' ) ) {
			self::log( 'el pedido ' . (int) $order->get_id() . ' no admite notas; no se pudo registrar la trazabilidad.' );
			return;
		}
		$order->add_order_note( $message, false );
	}

	/** @return array<string,mixed> */
	private static function state( WC_Order $order ): array {
		$raw = $order->get_meta( self::META_EARNINGS );
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
			self::log( 'el pedido ' . (int) $order->get_id() . ' no admite metas; no se guardó el estado de las ganancias.' );
			return;
		}

		$order->update_meta_data( self::META_EARNINGS, $state );
		$order->save_meta_data();
	}

	private static function metaFloat( WC_Order $order, string $key ): float {
		$raw = $order->get_meta( $key );
		if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return 0.0;
		}

		return max( 0.0, (float) $raw );
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
		error_log( '[hwe] loyalty: ' . $message );
	}
}
