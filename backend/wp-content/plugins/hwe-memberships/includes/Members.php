<?php
/**
 * Estado de un socio (`HWE_Memberships_Members`): lectura calculada por fechas, sin escribir.
 *
 * `state()` es la lectura pública del módulo. Combina:
 *  · las concesiones vivas del usuario (`status` `active` o `scheduled`), leídas por
 *    `HWE_Memberships_Grants::forUser()` y ordenadas por la línea de tiempo;
 *  · la fila de `members` solo para los datos que no se deducen de las concesiones: número de
 *    socio, «socio desde» y la congelación.
 *
 * Si la caché de `members` y el cálculo por fechas no coinciden, **manda el cálculo**: una
 * caducidad que la tarea diaria aún no ha materializado se ve como vencida igualmente. Por eso
 * esta clase nunca escribe ni abre transacción.
 *
 * Forma del array que devuelve `state()`:
 *  · `level_id`       int   Nivel efectivo; `0` si no hay (o si el socio está congelado).
 *  · `rank`           int   Rango del nivel efectivo; `0` si no hay.
 *  · `status`         string `active`, `none` o `frozen`.
 *  · `ends_at`        ?DateTimeImmutable  Fin de la cobertura; `null` si es indefinida o no hay.
 *  · `queue`          list<array<string,mixed>>  Tiempo programado por nivel (Timeline::queue).
 *  · `member_since`   ?DateTimeImmutable  Alta como socio.
 *  · `member_number`  string  Número de socio; `''` si todavía no tiene.
 *  · `grant_key`      string  Clave de la concesión efectiva; `''` si no hay.
 *
 * La congelación se superpone a las fechas: desde `members.frozen_at`, el estado es `frozen`,
 * el rango efectivo `0` y `ends_at` nulo. Con `$at` anterior a la congelación se devuelve el
 * estado histórico calculado normalmente (lo usa la tarea diaria y los tests).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Members {

	public const STATUS_NONE   = 'none';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_FROZEN = 'frozen';

	/**
	 * Estado del socio en `$at` (por defecto, ahora). Solo lee.
	 *
	 * @return array{level_id:int,rank:int,status:string,ends_at:?DateTimeImmutable,queue:list<array<string,mixed>>,member_since:?DateTimeImmutable,member_number:string,grant_key:string}
	 */
	public static function state( int $userId, ?DateTimeImmutable $at = null ): array {
		$at = null === $at ? self::now() : self::utc( $at );

		if ( $userId <= 0 ) {
			return self::emptyState();
		}

		$row    = self::memberRow( $userId );
		$grants = class_exists( 'HWE_Memberships_Grants' )
			? HWE_Memberships_Grants::forUser( $userId )
			: array();

		$state = self::stateFromGrants( $grants, $at );

		$frozenAt = self::date( $row?->frozen_at ?? null );
		if ( null !== $frozenAt && $at >= $frozenAt ) {
			$state['level_id']  = 0;
			$state['rank']      = 0;
			$state['status']    = self::STATUS_FROZEN;
			$state['ends_at']   = null;
			$state['grant_key'] = '';
		}

		$state['member_since']  = self::date( $row?->member_since ?? null );
		$state['member_number'] = is_scalar( $row?->member_number ?? null )
			? trim( (string) $row->member_number )
			: '';

		return $state;
	}

	/**
	 * Nivel efectivo a partir de las concesiones, sin leer ni escribir nada más. Es la pieza
	 * que comparten la lectura pública y las operaciones de escritura para calcular el estado
	 * anterior y posterior dentro de la misma transacción.
	 *
	 * @param list<array<string,mixed>> $grants
	 * @return array{level_id:int,rank:int,status:string,ends_at:?DateTimeImmutable,queue:list<array<string,mixed>>,grant_key:string}
	 */
	public static function stateFromGrants( array $grants, ?DateTimeImmutable $at = null ): array {
		$at = null === $at ? self::now() : self::utc( $at );

		if ( ! class_exists( 'HWE_Memberships_Timeline' ) ) {
			$state = self::emptyState();
			unset( $state['member_since'], $state['member_number'] );
			return $state;
		}

		$effective = HWE_Memberships_Timeline::effective( $grants, $at );
		if ( null !== $effective && (int) ( $effective['rank'] ?? 0 ) < 1 ) {
			// Una concesión de un nivel que ya no tiene rango válido no da acceso.
			$effective = null;
		}

		return array(
			'level_id'  => null === $effective ? 0 : (int) ( $effective['level_id'] ?? 0 ),
			'rank'      => null === $effective ? 0 : (int) ( $effective['rank'] ?? 0 ),
			'status'    => null === $effective ? self::STATUS_NONE : self::STATUS_ACTIVE,
			'ends_at'   => null !== $effective && ( $effective['ends_at'] ?? null ) instanceof DateTimeImmutable
				? $effective['ends_at']
				: null,
			'queue'     => HWE_Memberships_Timeline::queue( $grants, $at ),
			'grant_key' => null === $effective ? '' : (string) ( $effective['key'] ?? '' ),
		);
	}

	/** Rango efectivo: 0 si el socio está congelado o no tiene nivel vigente. */
	public static function effectiveRank( int $userId ): int {
		$state = self::state( $userId );

		return in_array( $state['status'], array( self::STATUS_NONE, self::STATUS_FROZEN ), true )
			? 0
			: (int) $state['rank'];
	}

	/**
	 * ¿El socio está congelado? Consulta solo la fila de `members`, sin cargar concesiones.
	 * La tarea diaria lo usa para no caducar nada mientras dura la congelación.
	 */
	public static function isFrozen( int $userId ): bool {
		if ( $userId <= 0 ) {
			return false;
		}

		$row = self::memberRow( $userId );

		return null !== self::date( $row?->frozen_at ?? null );
	}

	/** @return object|null */
	private static function memberRow( int $userId ): ?object {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || $userId <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Memberships_Schema::membersTable() . ' WHERE user_id = %d LIMIT 1',
				$userId
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/** @return array{level_id:int,rank:int,status:string,ends_at:?DateTimeImmutable,queue:list<array<string,mixed>>,member_since:?DateTimeImmutable,member_number:string,grant_key:string} */
	private static function emptyState(): array {
		return array(
			'level_id'      => 0,
			'rank'          => 0,
			'status'        => self::STATUS_NONE,
			'ends_at'       => null,
			'queue'         => array(),
			'member_since'  => null,
			'member_number' => '',
			'grant_key'     => '',
		);
	}

	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( '@' . time() );
	}

	private static function utc( DateTimeImmutable $at ): DateTimeImmutable {
		return $at->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/** Fecha de MySQL en UTC, o `null` si no es una fecha válida. */
	private static function date( mixed $value ): ?DateTimeImmutable {
		if ( $value instanceof DateTimeImmutable ) {
			return self::utc( $value );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
