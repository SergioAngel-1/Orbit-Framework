<?php
/**
 * Carné digital verificable por QR (spec §9).
 *
 * El socio pide su carné por el BFF (con su sesión); WordPress emite un token aleatorio de
 * 256 bits ligado al usuario y con caducidad configurable (`card_token_ttl_hours`, por defecto
 * 24 h). En la base de datos solo queda el **SHA-256** del token, nunca el token en claro: la
 * búsqueda de `verify()` va por ese hash, que tiene índice único.
 *
 * Como mucho hay **un token vivo por usuario**. No se puede devolver el token vivo sin
 * guardarlo en claro, así que `issue()` revoca el anterior y emite uno nuevo en la misma
 * transacción, con la fila del socio bloqueada (`SELECT … FOR UPDATE`). El token en claro se
 * entrega una sola vez, en la respuesta.
 *
 * Solo se emite con nivel efectivo (activo y no congelado). La revocación se engancha a todo el
 * ciclo de la membresía (caducar, congelar, revocar y quitar); es idempotente y una red de
 * seguridad sobre la revocación que `HWE_Memberships_Grants` ya hace en sus transacciones.
 *
 * `verify()` devuelve solo los datos mínimos de §9: vigencia, nombre y slug del nivel, «socio
 * desde», número de socio (con el prefijo de la configuración, nunca el id de usuario), nombre
 * enmascarado y, solo con el módulo `customer-identity` activo, el documento enmascarado. Un
 * token inexistente, caducado o revocado devuelve `null`; la ruta interna lo traduce siempre a
 * la misma respuesta `{ valid: false }`, sin distinguir el motivo. La comprobación actualiza
 * `last_used_at` (un dato de diagnóstico, no de estado): es la única escritura de la lectura y
 * su fallo no cambia el resultado.
 *
 * Las reglas de nombre y documento se reimplementan aquí a partir del contrato de datos, sin
 * llamar a clases de otros módulos: el BFF es quien compone los textos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Card {

	/** Bytes del token: 32 = 256 bits de entropía. */
	public const TOKEN_BYTES = 32;

	/** Longitud del token en hexadecimal (doble de los bytes). */
	public const TOKEN_LENGTH = 64;

	/** Metas del módulo `customer-identity` (contrato de lectura; no se usa su clase). */
	public const DOCUMENT_TYPE_META   = 'billing_document_type';
	public const DOCUMENT_NUMBER_META = 'billing_document';

	/** Dígitos del documento que deja ver la verificación pública. */
	private const DOCUMENT_VISIBLE_CHARS = 3;

	/**
	 * Engancha la revocación a los eventos del ciclo. La llama `HWE_Memberships::boot()`.
	 *
	 * `Grants` ya revoca los tokens dentro de sus transacciones; estos oyentes son la red de
	 * seguridad idempotente para la caducidad que materialice la tarea diaria y para cualquier
	 * cambio de nivel a 0.
	 */
	public static function register(): void {
		add_action( 'hwe_membership_expired', array( self::class, 'onExpired' ), 10, 3 );
		add_action( 'hwe_membership_frozen', array( self::class, 'onFrozen' ), 10, 2 );
		add_action( 'hwe_membership_revoked', array( self::class, 'onRevoked' ), 10, 3 );
		add_action( 'hwe_membership_removed_by_admin', array( self::class, 'onRemoved' ), 10, 3 );
		add_action( 'hwe_membership_level_changed', array( self::class, 'onLevelChanged' ), 10, 3 );
	}

	// ------------------------------------------------------------------------
	//  Emisión
	// ------------------------------------------------------------------------

	/**
	 * Emite un carné para el usuario y devuelve `token` y `expires_at` (ISO 8601 UTC).
	 *
	 * Devuelve `null` sin nivel efectivo, con el carné o el módulo apagados, o si la escritura
	 * falla. En la base de datos solo se guarda el hash; el vivo anterior queda revocado.
	 *
	 * @return array{token:string,expires_at:string}|null
	 */
	public static function issue( int $userId ): ?array {
		if ( $userId <= 0 || ! class_exists( 'HWE_Memberships_Settings' ) || ! HWE_Memberships_Settings::cardActive() ) {
			return null;
		}

		if ( null === self::effectiveState( $userId ) ) {
			return null;
		}

		$settings  = HWE_Memberships_Settings::get();
		$ttl       = max( 1, (int) ( $settings['card_token_ttl_hours'] ?? 24 ) );
		$now       = self::now();
		$nowSql    = gmdate( 'Y-m-d H:i:s', $now->getTimestamp() );
		$expires   = $now->modify( '+' . $ttl . ' hours' );
		$expireSql = gmdate( 'Y-m-d H:i:s', $expires->getTimestamp() );

		try {
			$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );
		} catch ( \Throwable $e ) {
			self::log( 'no se pudo generar el token del carné del usuario ' . $userId . '.' );
			return null;
		}
		$hash = hash( 'sha256', $token );

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			self::log( 'no hay base de datos para emitir el carné del usuario ' . $userId . '.' );
			return null;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			self::log( 'no se pudo abrir la transacción del carné del usuario ' . $userId . '.' );
			return null;
		}

		// Fila del socio bloqueada: dos peticiones del mismo usuario no emiten dos tokens vivos.
		$prepared = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . HWE_Memberships_Schema::membersTable()
				. ' (user_id, updated_at) VALUES (%d, %s)',
				$userId,
				$nowSql
			)
		);
		if ( false === $prepared ) {
			self::abort( 'no se pudo preparar la fila del socio ' . $userId . ' para el carné.' );
			return null;
		}

		$locked = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT user_id FROM ' . HWE_Memberships_Schema::membersTable()
				. ' WHERE user_id = %d FOR UPDATE',
				$userId
			)
		);
		if ( ! is_object( $locked ) ) {
			self::abort( 'no se pudo bloquear la fila del socio ' . $userId . ' para el carné.' );
			return null;
		}

		// Un solo token vivo: el anterior (si lo hay) queda revocado aquí mismo.
		$revoked = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . HWE_Memberships_Schema::cardTokensTable()
				. ' SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL',
				$nowSql,
				$userId
			)
		);
		if ( false === $revoked ) {
			self::abort( 'no se pudieron revocar los tokens anteriores del usuario ' . $userId . '.' );
			return null;
		}

		$inserted = $wpdb->insert(
			HWE_Memberships_Schema::cardTokensTable(),
			array(
				'user_id'      => $userId,
				'token_hash'   => $hash,
				'expires_at'   => $expireSql,
				'revoked_at'   => null,
				'last_used_at' => null,
				'created_at'   => $nowSql,
			)
		);
		if ( ! $inserted ) {
			self::abort( 'no se pudo guardar el token del carné del usuario ' . $userId . '.' );
			return null;
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			self::abort( 'no se pudo confirmar el carné del usuario ' . $userId . '.' );
			return null;
		}

		return array(
			'token'      => $token,
			'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expires->getTimestamp() ),
		);
	}

	// ------------------------------------------------------------------------
	//  Revocación
	// ------------------------------------------------------------------------

	/**
	 * Revoca los tokens vivos del usuario. Idempotente: solo toca las filas sin `revoked_at`.
	 *
	 * Es una única sentencia atómica de conjunto, no una lectura-modificación-escritura, así que
	 * no necesita transacción propia; se ejecuta después del COMMIT de `Grants` o de la tarea
	 * diaria, nunca dentro de otra transacción.
	 */
	public static function revoke( int $userId ): void {
		if ( $userId <= 0 ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			self::log( 'no hay base de datos para revocar el carné del usuario ' . $userId . '.' );
			return;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . HWE_Memberships_Schema::cardTokensTable()
				. ' SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL',
				gmdate( 'Y-m-d H:i:s', time() ),
				$userId
			)
		);
		if ( false === $updated ) {
			self::log( 'no se pudo revocar el carné del usuario ' . $userId . '.' );
		}
	}

	/** El carné caducó (`hwe_membership_expired`). */
	public static function onExpired( int $userId, int $grantId = 0, int $levelId = 0 ): void {
		self::revoke( $userId );
	}

	/** La membresía se congeló (`hwe_membership_frozen`). */
	public static function onFrozen( int $userId, int $actorId = 0 ): void {
		self::revoke( $userId );
	}

	/** Se revocó una concesión (`hwe_membership_revoked`). */
	public static function onRevoked( int $userId, int $grantId = 0, int $levelId = 0 ): void {
		self::revoke( $userId );
	}

	/** Un administrador quitó la membresía (`hwe_membership_removed_by_admin`). */
	public static function onRemoved( int $userId, int $grantId = 0, int $oldLevelId = 0 ): void {
		self::revoke( $userId );
	}

	/**
	 * Cambió el nivel efectivo. Solo revoca cuando el nivel nuevo es 0 (se quedó sin membresía);
	 * una mejora o una renovación mantienen el carné vigente, que ya leerá el nivel actual.
	 */
	public static function onLevelChanged( int $userId, int $oldLevelId = 0, int $newLevelId = 0 ): void {
		if ( $newLevelId <= 0 ) {
			self::revoke( $userId );
		}
	}

	/** Días que se conserva un token caducado antes de borrarlo (diagnóstico y soporte). */
	public const PURGE_AFTER_DAYS = 7;

	/** Filas que borra como máximo cada sentencia de la limpieza. */
	private const PURGE_CHUNK = 1000;

	/** Sentencias como máximo por pasada, para que la tarea diaria no se eternice. */
	private const PURGE_MAX_CHUNKS = 20;

	/**
	 * Borra los tokens caducados hace más de `PURGE_AFTER_DAYS`. Cada petición de carné inserta una
	 * fila y revoca la anterior (no la borra), así que sin esta limpieza la tabla crecería sin
	 * tope. Lo llama la tarea diaria; borra en trozos acotados con el índice `expires_at`.
	 *
	 * @return int filas borradas
	 */
	public static function purgeExpired(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'HWE_Memberships_Schema' ) ) {
			return 0;
		}

		$before  = gmdate( 'Y-m-d H:i:s', time() - self::PURGE_AFTER_DAYS * 86400 );
		$deleted = 0;

		for ( $chunk = 0; $chunk < self::PURGE_MAX_CHUNKS; $chunk++ ) {
			$count = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM ' . HWE_Memberships_Schema::cardTokensTable() . ' WHERE expires_at < %s LIMIT %d',
					$before,
					self::PURGE_CHUNK
				)
			);
			if ( false === $count ) {
				self::log( 'no se pudieron borrar los tokens del carné caducados.' );
				break;
			}

			$deleted += (int) $count;
			if ( (int) $count < self::PURGE_CHUNK ) {
				break;
			}
		}

		return $deleted;
	}

	// ------------------------------------------------------------------------
	//  Verificación
	// ------------------------------------------------------------------------

	/**
	 * Verificación pública del carné por su token en claro.
	 *
	 * Devuelve los datos mínimos de §9 o `null` si el token no existe, está caducado, revocado,
	 * el socio no tiene nivel efectivo ahora o el nivel ya no existe. Nunca incluye id de
	 * usuario, correo ni login.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function verify( string $token ): ?array {
		$token = trim( $token );
		if ( 1 !== preg_match( '/^[0-9a-f]{' . self::TOKEN_LENGTH . '}$/', $token ) ) {
			return null;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'HWE_Memberships_Schema' ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Memberships_Schema::cardTokensTable()
				. ' WHERE token_hash = %s LIMIT 1',
				hash( 'sha256', $token )
			)
		);
		if ( ! is_object( $row ) ) {
			return null;
		}

		$userId = (int) ( $row->user_id ?? 0 );
		if ( $userId <= 0 ) {
			return null;
		}

		$revokedAt = trim( (string) ( $row->revoked_at ?? '' ) );
		if ( '' !== $revokedAt ) {
			return null;
		}

		$now     = self::now();
		$expires = self::date( $row->expires_at ?? null );
		if ( null === $expires || $expires <= $now ) {
			return null;
		}

		$state = self::effectiveState( $userId );
		if ( null === $state ) {
			return null;
		}

		$level = HWE_Memberships_Levels::get( (int) $state['level_id'] );
		if ( null === $level || (int) ( $level['rank'] ?? 0 ) < 1 ) {
			return null;
		}

		self::touch( $userId, (int) ( $row->id ?? 0 ), $now );

		$settings    = HWE_Memberships_Settings::get();
		$memberSince = $state['member_since'] ?? null;
		$data        = array(
			'valid'         => true,
			'level'         => array(
				'name' => self::text( $level['name'] ?? '' ),
				'slug' => self::text( $level['slug'] ?? '' ),
			),
			'member_since'  => $memberSince instanceof DateTimeImmutable
				? gmdate( 'Y-m-d\TH:i:s\Z', $memberSince->getTimestamp() )
				: null,
			'member_number' => self::memberNumber(
				(string) ( $state['member_number'] ?? '' ),
				(string) ( $settings['member_number_prefix'] ?? '' )
			),
			'member_name'   => self::maskedName( $userId ),
		);

		$document = self::document( $userId );
		if ( array() !== $document ) {
			$data['document'] = $document;
		}

		return $data;
	}

	// ------------------------------------------------------------------------
	//  Datos públicos
	// ------------------------------------------------------------------------

	/**
	 * Nombre visible reducido a nombre de pila + inicial del apellido, con el mismo criterio
	 * de `loyalty` (`PublicName`): el `display_name` solo vale si no es el login ni un correo;
	 * si no, nombre y apellido reales. Sin nombre fiable devuelve `''`.
	 */
	private static function maskedName( int $userId ): string {
		if ( $userId <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user = get_userdata( $userId );
		if ( ! is_object( $user ) ) {
			return '';
		}

		$login   = self::userField( $user, 'user_login' );
		$display = self::userField( $user, 'display_name' );
		if ( '' !== $display && 0 !== strcasecmp( $display, $login ) && ! str_contains( $display, '@' ) ) {
			return self::reduceName( $display );
		}

		$first = self::userField( $user, 'first_name' );
		$last  = self::userField( $user, 'last_name' );
		if ( '' === $first || str_contains( $first, '@' ) || 0 === strcasecmp( $first, $login ) ) {
			return '';
		}

		return self::reduceName( '' !== $last ? $first . ' ' . mb_substr( $last, 0, 1 ) : $first );
	}

	/** Reduce un nombre a «pila + inicial del apellido»: «Ana María Pérez» → «Ana P.». */
	private static function reduceName( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) || array() === $parts ) {
			return '';
		}

		$first = (string) $parts[0];
		if ( count( $parts ) < 2 ) {
			return $first;
		}

		return $first . ' ' . mb_substr( (string) $parts[ count( $parts ) - 1 ], 0, 1 ) . '.';
	}

	/**
	 * Documento enmascarado, solo con `customer-identity` activo. Se leen las metas del usuario
	 * (contrato estable del módulo), nunca sus clases. Sin número no hay clave.
	 *
	 * @return array{type:string,number:string}|array{}
	 */
	private static function document( int $userId ): array {
		if ( $userId <= 0 || ! self::customerIdentityActive() || ! function_exists( 'get_user_meta' ) ) {
			return array();
		}

		$number = self::text( get_user_meta( $userId, self::DOCUMENT_NUMBER_META, true ) );
		if ( '' === $number ) {
			return array();
		}

		return array(
			'type'   => self::text( get_user_meta( $userId, self::DOCUMENT_TYPE_META, true ) ),
			'number' => self::maskDocument( $number ),
		);
	}

	/** `1020304050` → `***050`; un documento de 3 caracteres o menos no deja ver nada. */
	private static function maskDocument( string $number ): string {
		$visible = mb_strlen( $number ) > self::DOCUMENT_VISIBLE_CHARS
			? mb_substr( $number, -self::DOCUMENT_VISIBLE_CHARS )
			: '';

		return '***' . $visible;
	}

	/** ¿Está activo el módulo `customer-identity`? Sin Control Center, no se muestra documento. */
	private static function customerIdentityActive(): bool {
		return class_exists( 'HWE\\ControlCenter\\Modules' )
			&& \HWE\ControlCenter\Modules::isActive( 'customer-identity' );
	}

	/** Prefijo configurable + número propio del módulo; nunca el id de usuario. */
	private static function memberNumber( string $number, string $prefix ): string {
		$number = trim( $number );

		return '' === $number ? '' : $prefix . $number;
	}

	// ------------------------------------------------------------------------
	//  Utilidades
	// ------------------------------------------------------------------------

	/**
	 * Nivel efectivo actual (activo y no congelado), o `null`. La caducidad se decide por
	 * fechas en `Members::state()`; aquí no se escribe nada.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function effectiveState( int $userId ): ?array {
		if ( $userId <= 0 || ! class_exists( 'HWE_Memberships_Members' ) ) {
			return null;
		}

		$state = HWE_Memberships_Members::state( $userId );
		if ( ! is_array( $state ) ) {
			return null;
		}
		if ( HWE_Memberships_Members::STATUS_ACTIVE !== (string) ( $state['status'] ?? '' ) ) {
			return null;
		}
		if ( (int) ( $state['level_id'] ?? 0 ) < 1 ) {
			return null;
		}

		return $state;
	}

	/** Registra el último uso del token; es diagnóstico y su fallo no invalida la verificación. */
	private static function touch( int $userId, int $tokenId, DateTimeImmutable $now ): void {
		if ( $tokenId <= 0 ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$updated = $wpdb->update(
			HWE_Memberships_Schema::cardTokensTable(),
			array( 'last_used_at' => gmdate( 'Y-m-d H:i:s', $now->getTimestamp() ) ),
			array( 'id' => $tokenId )
		);
		if ( false === $updated ) {
			self::log( 'no se pudo registrar el último uso del carné del usuario ' . $userId . '.' );
		}
	}

	/** Aborta la transacción abierta, registra el motivo y deja el estado limpio. */
	private static function abort( string $message ): void {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->query( 'ROLLBACK' );
		}
		self::log( $message );
	}

	private static function userField( object $user, string $name ): string {
		$value = $user->{$name} ?? '';
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		return trim( function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( $value ) : $value );
	}

	private static function text( mixed $raw ): string {
		$value = is_scalar( $raw ) ? trim( (string) $raw ) : '';

		return function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( $value ) : $value;
	}

	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( '@' . time() );
	}

	/** Fecha de MySQL en UTC, o `null` si no es válida. */
	private static function date( mixed $value ): ?DateTimeImmutable {
		if ( $value instanceof DateTimeImmutable ) {
			return $value->setTimezone( new DateTimeZone( 'UTC' ) );
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

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
