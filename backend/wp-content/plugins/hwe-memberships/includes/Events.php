<?php
/**
 * Historial de membresías y acciones públicas del ciclo (spec §5.2 y §12).
 *
 * `record()` escribe una fila en `hwe_memberships_events` dentro de la transacción que abrió
 * `HWE_Memberships_Grants` y deja preparada la acción que corresponde. `flush()` las dispara
 * **después del COMMIT**; `clear()` las descarta si la operación hace ROLLBACK. Así ningún
 * consumidor ve una activación, una revocación o una congelación que luego no se confirmó.
 *
 * Hooks y argumentos (siempre ids numéricos, nunca datos personales):
 *  · `hwe_membership_activated`, `_renewed`, `_upgraded`, `_deferred`,
 *    `_granted_by_admin`, `_referral_gift_granted`:
 *    `( int $userId, int $grantId, int $levelId )`.
 *  · `hwe_membership_revoked`: `( int $userId, int $grantId, int $levelId )`.
 *  · `hwe_membership_removed_by_admin`: `( int $userId, 0, int $oldLevelId )`.
 *  · `hwe_membership_expired`: `( int $userId, int $grantId, int $levelId )`.
 *  · `hwe_membership_expiring`: `( int $userId, int $grantId, int $levelId, int $days )`.
 *  · `hwe_membership_frozen` / `_unfrozen`: `( int $userId, int $actorId )`.
 *  · `hwe_membership_level_changed`: `( int $userId, int $oldLevelId, int $newLevelId )`,
 *    siempre que el nivel efectivo cambie (0 = sin nivel).
 *
 * El tipo de evento es también el sufijo del hook específico (`activated` →
 * `hwe_membership_activated`). `data` se guarda como JSON estructurado; el texto que ve el
 * cliente se compone en el BFF, no aquí.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Events {

	public const ACTIVATED             = 'activated';
	public const RENEWED               = 'renewed';
	public const UPGRADED              = 'upgraded';
	public const DEFERRED              = 'deferred';
	public const EXPIRED               = 'expired';
	public const EXPIRING              = 'expiring';
	public const FROZEN                = 'frozen';
	public const UNFROZEN              = 'unfrozen';
	public const GRANTED_BY_ADMIN      = 'granted_by_admin';
	public const REMOVED_BY_ADMIN      = 'removed_by_admin';
	public const REVOKED               = 'revoked';
	public const REFERRAL_GIFT_GRANTED = 'referral_gift_granted';

	public const HOOK_ACTIVATED             = 'hwe_membership_activated';
	public const HOOK_RENEWED               = 'hwe_membership_renewed';
	public const HOOK_UPGRADED              = 'hwe_membership_upgraded';
	public const HOOK_DEFERRED              = 'hwe_membership_deferred';
	public const HOOK_EXPIRED               = 'hwe_membership_expired';
	public const HOOK_EXPIRING              = 'hwe_membership_expiring';
	public const HOOK_FROZEN                = 'hwe_membership_frozen';
	public const HOOK_UNFROZEN              = 'hwe_membership_unfrozen';
	public const HOOK_GRANTED_BY_ADMIN      = 'hwe_membership_granted_by_admin';
	public const HOOK_REMOVED_BY_ADMIN      = 'hwe_membership_removed_by_admin';
	public const HOOK_REVOKED               = 'hwe_membership_revoked';
	public const HOOK_REFERRAL_GIFT_GRANTED = 'hwe_membership_referral_gift_granted';
	public const HOOK_LEVEL_CHANGED         = 'hwe_membership_level_changed';

	/** Acciones preparadas durante la transacción, a la espera del COMMIT. */
	private static array $pending = array();

	/**
	 * Escribe una fila del historial y prepara sus hooks. Devuelve el id del evento o `null`
	 * si no se pudo escribir.
	 *
	 * `$hookLevelId` es el nivel que recibe el hook específico (el de la concesión afectada);
	 * por defecto el nivel nuevo. `levelFrom`/`levelTo` son el nivel efectivo antes y después
	 * (0 = ninguno) y solo generan `level_changed` cuando cambian.
	 *
	 * @param array<string,mixed> $data Datos estructurados (se guardan como JSON).
	 */
	public static function record(
		int $userId,
		?int $grantId,
		string $type,
		int $levelFrom,
		int $levelTo,
		string $actor = 'system',
		?int $actorId = null,
		array $data = array(),
		?string $now = null,
		?int $hookLevelId = null
	): ?int {
		global $wpdb;

		$grantId   = null === $grantId || $grantId <= 0 ? null : $grantId;
		$actorId   = null === $actorId || $actorId <= 0 ? null : $actorId;
		$hookLevel = null === $hookLevelId ? $levelTo : $hookLevelId;
		$createdAt = null === $now || '' === $now ? gmdate( 'Y-m-d H:i:s' ) : $now;

		$inserted = $wpdb->insert(
			HWE_Memberships_Schema::eventsTable(),
			array(
				'user_id'    => $userId,
				'grant_id'   => $grantId,
				'type'       => $type,
				'level_from' => $levelFrom,
				'level_to'   => $levelTo,
				'actor'      => $actor,
				'actor_id'   => $actorId,
				'data'       => array() === $data ? null : self::json( $data ),
				'created_at' => $createdAt,
			)
		);

		$eventId = $inserted ? (int) $wpdb->insert_id : 0;
		if ( $eventId <= 0 ) {
			self::log( 'no se pudo escribir el evento «' . $type . '» del usuario ' . $userId . '.' );
			return null;
		}

		self::queueFor( $type, $userId, (int) ( $grantId ?? 0 ), $levelFrom, $levelTo, $actorId, $hookLevel, $data );

		return $eventId;
	}

	/** Encola una acción para después del COMMIT. */
	public static function queue( string $hook, array $args ): void {
		self::$pending[] = array( $hook, array_values( $args ) );
	}

	/**
	 * Dispara las acciones acumuladas y vacía la cola. Se llama solo después de un COMMIT
	 * correcto. Devuelve cuántas acciones disparó.
	 */
	public static function flush(): int {
		$pending       = self::$pending;
		self::$pending = array();

		if ( ! function_exists( 'do_action' ) ) {
			return 0;
		}

		foreach ( $pending as $action ) {
			do_action( $action[0], ...$action[1] );
		}

		return count( $pending );
	}

	/** Descarta las acciones pendientes (ROLLBACK o fallo antes del COMMIT). */
	public static function clear(): void {
		self::$pending = array();
	}

	/**
	 * Acciones pendientes. Solo para diagnóstico y tests; no forma parte del contrato estable.
	 *
	 * @return list<array{0:string,1:list<mixed>}>
	 */
	public static function pending(): array {
		return self::$pending;
	}

	/**
	 * Prepara el hook específico del tipo y, si el nivel efectivo cambió, el general.
	 *
	 * @param array<string,mixed> $data
	 */
	private static function queueFor(
		string $type,
		int $userId,
		int $grantId,
		int $levelFrom,
		int $levelTo,
		?int $actorId,
		int $hookLevelId,
		array $data
	): void {
		$hooks = array(
			self::ACTIVATED             => self::HOOK_ACTIVATED,
			self::RENEWED               => self::HOOK_RENEWED,
			self::UPGRADED              => self::HOOK_UPGRADED,
			self::DEFERRED              => self::HOOK_DEFERRED,
			self::EXPIRED               => self::HOOK_EXPIRED,
			self::EXPIRING              => self::HOOK_EXPIRING,
			self::FROZEN                => self::HOOK_FROZEN,
			self::UNFROZEN              => self::HOOK_UNFROZEN,
			self::GRANTED_BY_ADMIN      => self::HOOK_GRANTED_BY_ADMIN,
			self::REMOVED_BY_ADMIN      => self::HOOK_REMOVED_BY_ADMIN,
			self::REVOKED               => self::HOOK_REVOKED,
			self::REFERRAL_GIFT_GRANTED => self::HOOK_REFERRAL_GIFT_GRANTED,
		);

		if ( isset( $hooks[ $type ] ) ) {
			if ( self::FROZEN === $type || self::UNFROZEN === $type ) {
				$args = array( $userId, (int) ( $actorId ?? 0 ) );
			} elseif ( self::EXPIRING === $type ) {
				$args = array( $userId, $grantId, $hookLevelId, (int) ( $data['days'] ?? 0 ) );
			} else {
				$args = array( $userId, $grantId, $hookLevelId );
			}
			self::queue( $hooks[ $type ], $args );
		}

		if ( $levelFrom !== $levelTo ) {
			self::queue( self::HOOK_LEVEL_CHANGED, array( $userId, $levelFrom, $levelTo ) );
		}
	}

	/** @param array<mixed> $data */
	private static function json( array $data ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
		return is_string( $json ) ? $json : '{}';
	}

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
