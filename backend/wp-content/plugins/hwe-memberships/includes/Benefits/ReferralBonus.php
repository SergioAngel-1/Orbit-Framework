<?php
/**
 * Comisión extra del referidor N1 (spec §7.5).
 *
 * Es un porcentaje adicional sobre la base de `loyalty` que se añade con el filtro
 * `hwe_loyalty_order_earnings` como una entrada de tipo propio (`membership_referral_bonus`,
 * declarado como abono por `HWE_Memberships_LoyaltyBridge`). `loyalty` la abona con su libro y
 * la revierte con el pedido como cualquier otra ganancia, sin que membresías tenga que hacer
 * nada más.
 *
 * El nivel que cuenta es el del referidor **en el momento de cobrarse el pedido**: el filtro se
 * ejecuta dentro del procesamiento de ganancias de `loyalty`, así que se lee su estado actual.
 * El referidor se recuerda al confirmarse la atribución (`_hwe_membership_referrer`), porque
 * `loyalty` no publica la lectura de la atribución.
 *
 * Sin referidor, con el beneficio apagado, con el nivel sin configurar o con el referidor
 * congelado, el array de ganancias sale intacto. El redondeo es el de `loyalty`: hacia abajo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Benefit_Referral_Bonus extends HWE_Memberships_Benefit {

	/** Clave estable del tipo. */
	public const KEY = 'referral_bonus';

	/** Tipo de movimiento con el que se abona en `loyalty`. */
	public const ENTRY_TYPE = 'membership_referral_bonus';

	public function key(): string {
		return self::KEY;
	}

	public function dependsOn(): ?string {
		return 'loyalty:referrals';
	}

	public function fields(): array {
		return array(
			'percent' => array(
				'type'    => self::FIELD_PERCENTAGE,
				'label'   => function_exists( '__' ) ? __( 'Comisión extra al referidor (%)', 'hwe' ) : 'Comisión extra al referidor (%)',
				'default' => 0,
				'min'     => 0,
				'max'     => 100,
			),
		);
	}

	public function publicData( int $levelId ): array {
		$config = $this->configFor( $levelId );

		return array(
			'key'     => $this->key(),
			'percent' => null === $config ? 0 : max( 0, min( 100, (int) ( $config['percent'] ?? 0 ) ) ),
		);
	}

	public function stateFor( int $userId ): array {
		$state  = parent::stateFor( $userId );
		$config = null;

		if ( $state['active'] && (int) $state['level_id'] > 0 ) {
			$config = $this->configFor( (int) $state['level_id'] );
		}

		$state['percent'] = null === $config ? 0 : max( 0, min( 100, (int) ( $config['percent'] ?? 0 ) ) );

		return $state;
	}

	protected function registerHooks(): void {
		add_filter( 'hwe_loyalty_order_earnings', array( $this, 'earnings' ), 10, 4 );
	}

	/**
	 * Añade la comisión extra al array de ganancias de `loyalty`. Cualquier entrada inesperada
	 * deja el array como estaba: el filtro nunca rompe el cobro.
	 *
	 * @param mixed $earnings
	 * @param mixed $order
	 * @param mixed $base
	 * @param mixed $settings
	 * @return mixed
	 */
	public function earnings( $earnings, $order, $base = 0.0, $settings = array() ) {
		if ( ! is_array( $earnings ) || ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
			return $earnings;
		}
		if ( ! $this->available() ) {
			return $earnings;
		}

		$buyerId = (int) $order->get_customer_id();
		if ( $buyerId <= 0 ) {
			return $earnings;
		}

		$referrerId = class_exists( 'HWE_Memberships_LoyaltyBridge' )
			? HWE_Memberships_LoyaltyBridge::referrerOf( $buyerId )
			: 0;
		if ( $referrerId <= 0 || $referrerId === $buyerId ) {
			return $earnings;
		}

		$percent = $this->percentFor( $referrerId );
		if ( $percent <= 0 ) {
			return $earnings;
		}

		$amount = is_numeric( $base ) ? max( 0.0, (float) $base ) : 0.0;
		$points = (int) floor( $amount * $percent / 100 );
		if ( $points <= 0 ) {
			return $earnings;
		}

		foreach ( $earnings as $entry ) {
			if (
				is_array( $entry )
				&& self::ENTRY_TYPE === (string) ( $entry['type'] ?? '' )
				&& $referrerId === (int) ( $entry['user_id'] ?? 0 )
			) {
				return $earnings; // Ya añadido por otra pasada del mismo filtro.
			}
		}

		$orderId = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$level   = class_exists( 'HWE_Memberships_Members' )
			? (int) ( HWE_Memberships_Members::state( $referrerId )['level_id'] ?? 0 )
			: 0;

		$earnings[] = array(
			'user_id' => $referrerId,
			'type'    => self::ENTRY_TYPE,
			'points'  => $points,
			'ref'     => array( 'type' => 'user', 'id' => $buyerId ),
			'data'    => array(
				'order'    => $orderId,
				'level_id' => $level,
				'percent'  => $percent,
			),
		);

		return $earnings;
	}

	/** Porcentaje adicional del nivel efectivo del referidor; `0` si no tiene el beneficio. */
	private function percentFor( int $userId ): int {
		if ( ! $this->isActiveFor( $userId ) || ! class_exists( 'HWE_Memberships_Members' ) ) {
			return 0;
		}

		$state  = HWE_Memberships_Members::state( $userId );
		$config = $this->configFor( (int) ( $state['level_id'] ?? 0 ) );
		if ( null === $config || empty( $config['enabled'] ) ) {
			return 0;
		}

		return max( 0, min( 100, (int) ( $config['percent'] ?? 0 ) ) );
	}
}
