<?php
/**
 * Membresía de regalo para el referido (spec §7.5).
 *
 * Es un beneficio **exclusivo de los niveles que lo activan** y lo recibe la cuenta nueva que se
 * registró con el código del referidor; el referidor no recibe membresía, solo los puntos que ya
 * le da `loyalty` con su propia configuración.
 *
 * Campos del nivel del referidor: a qué nivel se regala (`level`), cuántos días (`days`, ≥ 1) y
 * cuántos regalos caben por periodo (`max_per_period`, ≥ 1). El «periodo» es la concesión activa
 * del referidor en ese nivel: cada concesión lleva su contador (`grants.referral_gifts`) y una
 * renovación (concesión nueva) empieza a contar de cero. El contador y la concesión del regalo
 * se escriben en la MISMA transacción con las filas del referidor y del referido bloqueadas en
 * orden estable (`Grants::grantReferralGift()`), así que dos verificaciones simultáneas no pasan
 * del tope (Foco de revisión 2).
 *
 * Cuándo se concede (`referral_gift_requires_email_verification`, §10.1):
 *  · encendida (por defecto): al verificar el correo (`hwe_email_verified`), con atribución
 *    recordada. Una cuenta que nunca verificó —incluidas las creadas en wp-admin, que `loyalty`
 *    da por confirmadas— no lo recibe;
 *  · apagada: al confirmarse la atribución (`hwe_loyalty_attribution_confirmed`).
 *
 * La atribución se recuerda en `_hwe_membership_referrer` porque `loyalty` no publica su
 * lectura. Una sola concesión por referido (clave `gift:<referido>`), sin puntos por activación
 * y sin regalo retroactivo si el módulo estaba apagado. Si el referido ya tiene ese nivel o uno
 * superior, la concesión se encola sola con la línea de tiempo. Al alcanzar el tope, el referido
 * se registra con normalidad y queda constancia en el historial del referidor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Benefit_Referral_Gift extends HWE_Memberships_Benefit {

	/** Clave estable del tipo. */
	public const KEY = 'referral_gift';

	public function key(): string {
		return self::KEY;
	}

	public function dependsOn(): ?string {
		return 'loyalty:referrals';
	}

	public function fields(): array {
		return array(
			'level'          => array(
				'type'    => self::FIELD_LEVEL,
				'label'   => function_exists( '__' ) ? __( 'Nivel que se regala', 'hwe' ) : 'Nivel que se regala',
				'default' => 0,
				'min'     => 0,
			),
			'days'           => array(
				'type'    => self::FIELD_NUMBER,
				'label'   => function_exists( '__' ) ? __( 'Días del regalo', 'hwe' ) : 'Días del regalo',
				'default' => 30,
				'min'     => 1,
				'max'     => 3650,
			),
			'max_per_period' => array(
				'type'    => self::FIELD_NUMBER,
				'label'   => function_exists( '__' ) ? __( 'Máximo de regalos por periodo', 'hwe' ) : 'Máximo de regalos por periodo',
				'default' => 1,
				'min'     => 1,
				'max'     => 1000,
			),
		);
	}

	public function publicData( int $levelId ): array {
		$config = $this->configFor( $levelId );

		return array(
			'key'            => $this->key(),
			'level'          => null === $config ? 0 : max( 0, (int) ( $config['level'] ?? 0 ) ),
			'days'           => null === $config ? 0 : max( 0, (int) ( $config['days'] ?? 0 ) ),
			'max_per_period' => null === $config ? 0 : max( 0, (int) ( $config['max_per_period'] ?? 0 ) ),
		);
	}

	/**
	 * Estado del referidor con los regalos que le quedan en el periodo de su concesión activa
	 * (`GET /api/memberships/me` lo publica en la tarea del BFF).
	 */
	public function stateFor( int $userId ): array {
		$state = parent::stateFor( $userId );
		$max   = 0;
		$used  = 0;

		if ( $state['active'] && (int) $state['level_id'] > 0 ) {
			$config = $this->configFor( (int) $state['level_id'] );
			$max    = null === $config ? 0 : max( 0, (int) ( $config['max_per_period'] ?? 0 ) );
			$member = class_exists( 'HWE_Memberships_Members' ) ? HWE_Memberships_Members::state( $userId ) : array();
			$used   = $this->usedFor( $userId, (string) ( $member['grant_key'] ?? '' ) );
		}

		$state['gifts_max']       = $max;
		$state['gifts_used']      = min( $used, $max );
		$state['gifts_remaining'] = max( 0, $max - $used );

		return $state;
	}

	protected function registerHooks(): void {
		add_action( 'hwe_loyalty_attribution_confirmed', array( $this, 'onAttributionConfirmed' ), 10, 2 );
		add_action( 'hwe_email_verified', array( $this, 'onEmailVerified' ), 10, 1 );
	}

	/**
	 * Atribución confirmada por `loyalty`: recuerda el referidor y, con la verificación apagada,
	 * concede el regalo en ese mismo momento.
	 */
	public function onAttributionConfirmed( int $userId, ?int $referrerId = null ): void {
		if ( $userId <= 0 || ! class_exists( 'HWE_Memberships_LoyaltyBridge' ) ) {
			return;
		}

		$referrerId = (int) ( $referrerId ?? 0 );
		if ( $referrerId <= 0 || $referrerId === $userId ) {
			if ( function_exists( 'delete_user_meta' ) ) {
				delete_user_meta( $userId, HWE_Memberships_LoyaltyBridge::REFERRER_META );
			}
			return;
		}

		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta( $userId, HWE_Memberships_LoyaltyBridge::REFERRER_META, $referrerId );
		} else {
			self::log( 'no se pudo recordar el referidor del usuario ' . $userId . ': falta update_user_meta().' );
			return;
		}

		if ( ! $this->requiresVerification() ) {
			$this->grantFor( $userId, $referrerId );
			return;
		}

		// La atribución puede confirmarse después de que el referido ya haya verificado su
		// correo (p. ej. aplicó el código más tarde): las dos condiciones ya se cumplen.
		if ( $this->emailVerified( $userId ) ) {
			$this->grantFor( $userId, $referrerId );
		}
	}

	/**
	 * Correo verificado. Con la verificación encendida es la única señal que concede el regalo;
	 * se comprueba además la meta que escribe `hwe-auth` para fallar cerrado ante una llamada
	 * sin verificación real.
	 */
	public function onEmailVerified( int $userId ): void {
		if ( $userId <= 0 || ! $this->requiresVerification() ) {
			return;
		}

		$referrerId = class_exists( 'HWE_Memberships_LoyaltyBridge' )
			? HWE_Memberships_LoyaltyBridge::referrerOf( $userId )
			: 0;
		if ( $referrerId <= 0 ) {
			return;
		}

		if ( ! $this->emailVerified( $userId ) ) {
			self::log(
				'el usuario ' . $userId . ' recibió la señal de verificación sin la meta de correo verificado; no se concede el regalo.'
			);
			return;
		}

		$this->grantFor( $userId, $referrerId );
	}

	/** ¿La opción del módulo exige correo verificado? (por defecto, sí) */
	private function requiresVerification(): bool {
		if ( ! class_exists( 'HWE_Memberships_Settings' ) ) {
			return true;
		}

		return (bool) ( HWE_Memberships_Settings::get()['referral_gift_requires_email_verification'] ?? true );
	}

	/** Meta de verificación que escribe `hwe-auth`; vacía o `0` cuentan como no verificada. */
	private function emailVerified( int $userId ): bool {
		if ( ! function_exists( 'get_user_meta' ) ) {
			return false;
		}

		$raw = (string) get_user_meta( $userId, 'hwe_email_verified', true );

		return '' !== $raw && '0' !== $raw;
	}

	/**
	 * Concede el regalo al referido con el nivel, los días y el tope que el referidor tiene
	 * AHORA. El servicio transaccional decide si cabe en el tope y si el referido lo recibe
	 * ahora o queda encolado.
	 */
	private function grantFor( int $refereeId, int $referrerId ): void {
		if ( ! class_exists( 'HWE_Memberships_Grants' ) || ! class_exists( 'HWE_Memberships_LoyaltyBridge' ) ) {
			self::log( 'no se puede conceder el regalo al usuario ' . $refereeId . ': faltan las piezas del módulo.' );
			return;
		}
		if ( ! HWE_Memberships_LoyaltyBridge::available( 'referrals' ) ) {
			self::log( 'no se concede el regalo al usuario ' . $refereeId . ': «Referidos» está apagado.' );
			return;
		}

		$config = $this->giftConfig( $referrerId );
		if ( null === $config ) {
			self::log( 'no se concede el regalo al usuario ' . $refereeId . ': el referidor ' . $referrerId . ' no tiene el beneficio activo.' );
			return;
		}

		$result = HWE_Memberships_Grants::grantReferralGift(
			$referrerId,
			$refereeId,
			$config['level'],
			$config['days'],
			$config['max'],
			array(
				'referrer_level_id' => $config['referrer_level'],
				'gift_level_id'     => $config['level'],
			)
		);

		switch ( (string) ( $result['reason'] ?? '' ) ) {
			case HWE_Memberships_Grants::RESULT_GRANTED:
			case HWE_Memberships_Grants::RESULT_DUPLICATE:
				return;

			case HWE_Memberships_Grants::RESULT_LIMIT:
				$this->recordLimit( $refereeId, $referrerId, $config, $result );
				return;

			case HWE_Memberships_Grants::RESULT_FROZEN:
				self::log( 'no se concede el regalo al usuario ' . $refereeId . ': el referidor ' . $referrerId . ' está congelado.' );
				return;

			case HWE_Memberships_Grants::RESULT_NO_MEMBERSHIP:
				self::log( 'no se concede el regalo al usuario ' . $refereeId . ': el referidor ' . $referrerId . ' no tiene membresía vigente.' );
				return;

			default:
				self::log( 'no se pudo conceder el regalo al usuario ' . $refereeId . ' (resultado: ' . (string) ( $result['reason'] ?? 'desconocido' ) . ').' );
				return;
		}
	}

	/**
	 * Configuración del regalo según el nivel efectivo del referidor. `null` si no tiene
	 * membresía, está congelado, no tiene el beneficio o le faltan campos válidos.
	 *
	 * @return array{level:int,days:int,max:int,referrer_level:int}|null
	 */
	private function giftConfig( int $referrerId ): ?array {
		if ( $referrerId <= 0 || ! class_exists( 'HWE_Memberships_Members' ) ) {
			return null;
		}
		if ( ! $this->isActiveFor( $referrerId ) ) {
			return null;
		}

		$state   = HWE_Memberships_Members::state( $referrerId );
		$levelId = (int) ( $state['level_id'] ?? 0 );
		if ( $levelId <= 0 || 'active' !== (string) ( $state['status'] ?? '' ) ) {
			return null;
		}

		$config = $this->configFor( $levelId );
		if ( null === $config || empty( $config['enabled'] ) ) {
			return null;
		}

		$target = max( 0, (int) ( $config['level'] ?? 0 ) );
		$days   = max( 0, (int) ( $config['days'] ?? 0 ) );
		$max    = max( 0, (int) ( $config['max_per_period'] ?? 0 ) );

		if ( $target <= 0 || $days < 1 || $max < 1 ) {
			self::log( 'el nivel ' . $levelId . ' tiene el regalo de referido mal configurado; no se concede.' );
			return null;
		}

		if ( class_exists( 'HWE_Memberships_Levels' ) ) {
			$level = HWE_Memberships_Levels::get( $target );
			if ( null === $level || (int) ( $level['rank'] ?? 0 ) < 1 ) {
				self::log( 'el nivel a regalar ' . $target . ' no existe o no tiene rango; no se concede.' );
				return null;
			}
		}

		return array(
			'level'          => $target,
			'days'           => $days,
			'max'            => $max,
			'referrer_level' => $levelId,
		);
	}

	/**
	 * Deja en el historial del referidor que el regalo no se concedió por tope. El referido se
	 * registra con normalidad y `loyalty` sigue funcionando igual.
	 *
	 * @param array{level:int,days:int,max:int,referrer_level:int} $config
	 * @param array<string,mixed>                                  $result
	 */
	private function recordLimit( int $refereeId, int $referrerId, array $config, array $result ): void {
		self::log(
			'el referidor ' . $referrerId . ' alcanzó el tope de regalos del periodo; el usuario '
			. $refereeId . ' se registra sin regalo.'
		);

		if ( ! class_exists( 'HWE_Memberships_Events' ) ) {
			return;
		}

		$levelId = (int) ( $result['referrer_level_id'] ?? $config['referrer_level'] );
		$eventId = HWE_Memberships_Events::record(
			$referrerId,
			(int) ( $result['referrer_grant_id'] ?? 0 ),
			HWE_Memberships_Events::REFERRAL_GIFT_LIMIT,
			$levelId,
			$levelId,
			'system',
			null,
			array(
				'referee'        => $refereeId,
				'gift_level_id'  => $config['level'],
				'days'           => $config['days'],
				'max_per_period' => $config['max'],
				'used'           => (int) ( $result['used'] ?? 0 ),
				'grant_key'      => (string) ( $result['referrer_grant_key'] ?? '' ),
			),
			gmdate( 'Y-m-d H:i:s' ),
			$levelId
		);

		if ( null === $eventId ) {
			self::log( 'no se pudo registrar en el historial del referidor ' . $referrerId . ' el tope de regalos.' );
		}

		HWE_Memberships_Events::flush();
	}

	/** Regalos ya consumidos por la concesión indicada del referidor. */
	private function usedFor( int $userId, string $grantKey ): int {
		if ( '' === $grantKey || ! class_exists( 'HWE_Memberships_Grants' ) ) {
			return 0;
		}

		foreach ( HWE_Memberships_Grants::forUser( $userId ) as $grant ) {
			if ( (string) ( $grant['key'] ?? '' ) === $grantKey ) {
				return max( 0, (int) ( $grant['referral_gifts'] ?? 0 ) );
			}
		}

		return 0;
	}
}
