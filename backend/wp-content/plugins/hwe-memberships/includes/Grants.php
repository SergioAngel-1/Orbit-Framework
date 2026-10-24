<?php
/**
 * Servicio de concesiones de membresía (spec §5.2–§5.5).
 *
 * Es el único punto que escribe concesiones, la caché de `members` y el historial. Toda
 * operación sigue el mismo patrón:
 *  1. `START TRANSACTION`;
 *  2. `INSERT IGNORE` de la fila del socio y `SELECT … FOR UPDATE` sobre ella (bloqueo por
 *     usuario, también para dos peticiones del mismo usuario);
 *  3. se comprueba la clave de idempotencia **dentro** de la transacción (además del índice
 *     único de la tabla) y se leen las concesiones vivas bloqueadas;
 *  4. se calcula la nueva línea de tiempo con `HWE_Memberships_Timeline`;
 *  5. se escriben las concesiones, la caché de `members`, el historial y los tokens afectados;
 *  6. `COMMIT`; solo entonces `HWE_Memberships_Events::flush()` dispara las acciones. Cualquier
 *     fallo hace `ROLLBACK`, vacía las acciones pendientes y registra el error sin PII.
 *
 * Estados de una concesión en BD: `scheduled`, `active`, `ended`, `revoked`. `Timeline` quita
 * del array las programadas revocadas; aquí la fila **no se borra**: se marca `revoked` con
 * `revoked_at` para conservar el historial. Las activas revocadas terminan ahora (`ends_at`) y
 * también quedan `revoked`. `removeAll` termina las activas (`ended`) y revoca las programadas.
 *
 * Número de socio: se asigna en la primera concesión con un contador propio en la opción
 * `hwe_memberships_member_sequence`, incrementado con la fila bloqueada. Es único, estable y
 * nunca es el id del usuario; el prefijo visible lo aplica el carné (Tarea 16).
 *
 * Hooks: `HWE_Memberships_Events::record()` decide el evento. En `grant()` el tipo es:
 * `granted_by_admin` para origen `admin`, `referral_gift_granted` para `referral_gift`, y para
 * el resto: `activated` si no había nivel efectivo y la concesión empieza ya, `renewed` si es
 * del mismo nivel efectivo, `upgraded` si el nivel efectivo sube, y `deferred` si queda
 * programada detrás de una cobertura superior.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Grants {

	public const ORIGIN_ORDER         = 'order';
	public const ORIGIN_ADMIN         = 'admin';
	public const ORIGIN_BULK          = 'bulk';
	public const ORIGIN_REFERRAL_GIFT = 'referral_gift';

	/** Orígenes que siempre existen; el filtro puede añadir más. */
	public const BASE_ORIGINS = array(
		self::ORIGIN_ORDER,
		self::ORIGIN_ADMIN,
		self::ORIGIN_BULK,
		self::ORIGIN_REFERRAL_GIFT,
	);

	/** Contador del número de socio (fila no autoload de `wp_options`). */
	public const MEMBER_SEQUENCE_OPTION = 'hwe_memberships_member_sequence';

	/** Longitud máxima de la clave de idempotencia (la columna es varchar(191)). */
	private const KEY_MAX = 191;

	// ------------------------------------------------------------------------
	//  Concesión
	// ------------------------------------------------------------------------

	/**
	 * Concede un nivel a un usuario. Devuelve el id de la concesión o `null` si la clave ya
	 * existía (idempotencia) o si la operación falló. `$days = null` o `0` = indefinida.
	 *
	 * @param array<mixed> $ref  `['type'=>…,'id'=>…]` o un único par `['order'=>55]`.
	 * @param array<mixed> $data Datos estructurados; `note` se guarda en su columna.
	 */
	public static function grant(
		int $userId,
		int $levelId,
		?int $days,
		string $origin,
		string $key,
		array $ref = array(),
		int $actorId = 0,
		array $data = array()
	): ?int {
		self::clearQueue();

		if ( $userId <= 0 || $levelId <= 0 || ! self::validUser( $userId ) ) {
			self::log( 'concesión rechazada: usuario o nivel inválido (' . $userId . '/' . $levelId . ').' );
			return null;
		}
		if ( null !== $days && $days < 0 ) {
			self::log( 'concesión rechazada para el usuario ' . $userId . ': duración negativa.' );
			return null;
		}
		$days = null === $days ? 0 : $days;

		$origin = self::normalizeOrigin( $origin );
		if ( '' === $origin || ! in_array( $origin, self::origins(), true ) ) {
			self::log( 'concesión rechazada para el usuario ' . $userId . ': origen «' . $origin . '» no permitido.' );
			return null;
		}

		$key = self::normalizeKey( $key );
		if ( '' === $key ) {
			self::log( 'concesión rechazada para el usuario ' . $userId . ': sin clave de idempotencia.' );
			return null;
		}

		$level = self::level( $levelId );
		if ( null === $level || (int) ( $level['rank'] ?? 0 ) < 1 ) {
			self::log( 'concesión rechazada para el usuario ' . $userId . ': el nivel ' . $levelId . ' no existe o no tiene rango.' );
			return null;
		}

		[ $refType, $refId ]              = self::normalizeRef( $ref );
		[ $actor, $actorId, $note, $json ] = self::normalizeMeta( $data, $actorId );
		if ( self::ORIGIN_ADMIN === $origin ) {
			// El origen ya dice que la concedió un administrador, aunque no llegue su id.
			$actor = 'admin';
		}

		$now    = self::now();
		$nowSql = self::sqlDate( $now );

		if ( ! self::begin() ) {
			self::log( 'no se pudo abrir la transacción de la concesión del usuario ' . $userId . '.' );
			return null;
		}
		if ( ! self::ensureMemberRow( $userId, $nowSql ) ) {
			self::fail( 'no se pudo preparar la fila del socio ' . $userId . '.' );
			return null;
		}
		$member = self::lockMember( $userId );
		if ( null === $member ) {
			self::fail( 'no se pudo bloquear la fila del socio ' . $userId . '.' );
			return null;
		}

		// Dentro de la transacción y con la fila del socio bloqueada: dos peticiones con la
		// misma clave no pueden crear dos concesiones.
		if ( null !== self::grantIdByKey( $key ) ) {
			self::rollback();
			return null;
		}

		$grants = self::forUser( $userId, true );
		$before = self::stateOf( $grants, $now );

		$new = array(
			'key'      => $key,
			'level_id' => $levelId,
			'rank'     => (int) $level['rank'],
			'days'     => (int) $days,
			'origin'   => $origin,
			'ref_type' => $refType,
			'ref_id'   => $refId,
			'actor'    => $actor,
			'actor_id' => $actorId,
			'note'     => $note,
			'data'     => $json,
		);

		$placed = HWE_Memberships_Timeline::place( $grants, $new, $now );
		$grant  = self::byKey( $placed, $key );
		if ( null === $grant ) {
			self::fail( 'la línea de tiempo no devolvió la concesión nueva del usuario ' . $userId . '.' );
			return null;
		}

		$grantId = self::insertGrant( $userId, $grant, $nowSql );
		if ( $grantId <= 0 ) {
			self::rollback();
			self::clearQueue();
			if ( null !== self::grantIdByKey( $key ) ) {
				return null; // Otra petición confirmó la misma clave primero.
			}
			self::log( 'no se pudo insertar la concesión «' . $key . '» del usuario ' . $userId . '.' );
			return null;
		}

		if ( ! self::persistChanged( $grants, $placed, $nowSql, $key ) ) {
			self::fail( 'no se pudieron actualizar las concesiones afectadas del usuario ' . $userId . '.' );
			return null;
		}

		$after  = self::stateOf( $placed, $now );
		$frozen = null !== self::date( $member?->frozen_at ?? null );

		$memberExtra = array();
		if ( '' === trim( (string) ( $member?->member_number ?? '' ) ) ) {
			$number = self::nextMemberNumber();
			if ( '' === $number ) {
				self::fail( 'no se pudo asignar el número de socio al usuario ' . $userId . '.' );
				return null;
			}
			$memberExtra['member_number'] = $number;
		}
		if ( null === self::date( $member?->member_since ?? null ) ) {
			$memberExtra['member_since'] = $nowSql;
		}

		if ( ! self::cacheMember( $userId, $frozen ? null : $after, $nowSql, $memberExtra ) ) {
			self::fail( 'no se pudo actualizar la caché del socio ' . $userId . '.' );
			return null;
		}

		$oldLevel = $frozen ? 0 : (int) $before['level_id'];
		$newLevel = $frozen ? 0 : (int) $after['level_id'];
		$type     = self::eventType( $origin, $before, $after, $grant );

		$eventData = array(
			'origin'    => $origin,
			'key'       => $key,
			'days'      => (int) $days,
			'starts_at' => self::sqlDate( $grant['starts_at'] ?? null ),
			'ends_at'   => self::sqlDate( $grant['ends_at'] ?? null ),
			'status'    => (string) ( $grant['status'] ?? '' ),
			'ref'       => array( 'type' => $refType, 'id' => $refId ),
		);

		$eventId = self::recordEvent(
			$userId,
			$grantId,
			$type,
			$oldLevel,
			$newLevel,
			$actor,
			$actorId,
			$eventData,
			$nowSql,
			(int) $grant['level_id']
		);
		if ( null === $eventId ) {
			self::fail( 'no se pudo registrar el historial de la concesión del usuario ' . $userId . '.' );
			return null;
		}

		if ( ! self::commit() ) {
			self::clearQueue();
			self::rollback();
			self::log( 'no se pudo confirmar la concesión «' . $key . '» del usuario ' . $userId . '.' );
			return null;
		}

		self::flush();
		return $grantId;
	}

	/**
	 * Revoca una concesión por su clave. Una programada se marca `revoked` (sin borrar la fila);
	 * una activa termina ahora y también queda `revoked`. Devuelve `false` si la clave no existe
	 * o ya estaba terminada.
	 */
	public static function revokeByKey( string $key, int $actorId = 0, string $reason = '' ): bool {
		self::clearQueue();

		$key = self::normalizeKey( $key );
		if ( '' === $key ) {
			return false;
		}

		$now    = self::now();
		$nowSql = self::sqlDate( $now );

		if ( ! self::begin() ) {
			self::log( 'no se pudo abrir la transacción de la revocación «' . $key . '».' );
			return false;
		}

		$target = self::rowByKey( $key );
		if ( null === $target ) {
			self::rollback();
			return false;
		}
		$status = (string) ( $target->status ?? '' );
		if ( ! in_array( $status, array( 'active', 'scheduled' ), true ) ) {
			self::rollback();
			return false;
		}

		$userId = (int) ( $target->user_id ?? 0 );
		if ( $userId <= 0 ) {
			self::rollback();
			self::log( 'la concesión «' . $key . '» no tiene un usuario válido.' );
			return false;
		}
		if ( ! self::ensureMemberRow( $userId, $nowSql ) ) {
			self::fail( 'no se pudo preparar la fila del socio ' . $userId . ' para revocar «' . $key . '».' );
			return false;
		}
		$member = self::lockMember( $userId );
		if ( null === $member ) {
			self::fail( 'no se pudo bloquear la fila del socio ' . $userId . ' para revocar «' . $key . '».' );
			return false;
		}

		$grants  = self::forUser( $userId, true );
		$current = self::byKey( $grants, $key );
		if ( null === $current || ! in_array( (string) $current['status'], array( 'active', 'scheduled' ), true ) ) {
			self::rollback();
			return false;
		}

		$before      = self::stateOf( $grants, $now );
		$afterGrants = HWE_Memberships_Timeline::revoke( $grants, $key, $now );
		$active      = 'active' === (string) $current['status'];

		$targetData = array(
			'status'     => 'revoked',
			'revoked_at' => $nowSql,
			'updated_at' => $nowSql,
		);
		if ( $active ) {
			$targetData['ends_at']        = $nowSql;
			$targetData['queued_seconds'] = null;
		}

		if ( false === self::updateGrant( (int) $current['id'], $targetData ) ) {
			self::fail( 'no se pudo marcar como revocada la concesión «' . $key . '».' );
			return false;
		}
		if ( ! self::persistChanged( $grants, $afterGrants, $nowSql, $key ) ) {
			self::fail( 'no se pudieron actualizar las concesiones liberadas al revocar «' . $key . '».' );
			return false;
		}

		$after  = self::stateOf( $afterGrants, $now );
		$frozen = null !== self::date( $member?->frozen_at ?? null );
		if ( ! self::cacheMember( $userId, $frozen ? null : $after, $nowSql ) ) {
			self::fail( 'no se pudo actualizar la caché del socio ' . $userId . ' al revocar «' . $key . '».' );
			return false;
		}
		if ( ! self::revokeTokens( $userId, $nowSql ) ) {
			self::fail( 'no se pudieron revocar los tokens del carné del usuario ' . $userId . '.' );
			return false;
		}

		$oldLevel = $frozen ? 0 : (int) $before['level_id'];
		$newLevel = $frozen ? 0 : (int) $after['level_id'];
		$eventId  = self::recordEvent(
			$userId,
			(int) $current['id'],
			HWE_Memberships_Events::REVOKED,
			$oldLevel,
			$newLevel,
			$actorId > 0 ? 'admin' : 'system',
			$actorId,
			array(
				'key'      => $key,
				'reason'   => $reason,
				'grant_id' => (int) $current['id'],
				'active'   => $active,
			),
			$nowSql,
			(int) $current['level_id']
		);
		if ( null === $eventId ) {
			self::fail( 'no se pudo registrar la revocación «' . $key . '».' );
			return false;
		}

		if ( ! self::commit() ) {
			self::clearQueue();
			self::rollback();
			self::log( 'no se pudo confirmar la revocación «' . $key . '».' );
			return false;
		}

		self::flush();
		return true;
	}

	/**
	 * Quita todas las concesiones vivas de un usuario: las activas terminan ahora y las
	 * programadas se marcan revocadas. Conserva filas, historial, número de socio y «socio
	 * desde». Devuelve `false` si no había nada vivo.
	 */
	public static function removeAll( int $userId, int $actorId, string $reason ): bool {
		self::clearQueue();

		if ( $userId <= 0 ) {
			return false;
		}

		$now    = self::now();
		$nowSql = self::sqlDate( $now );

		if ( ! self::begin() ) {
			self::log( 'no se pudo abrir la transacción para quitar las membresías del usuario ' . $userId . '.' );
			return false;
		}
		if ( ! self::ensureMemberRow( $userId, $nowSql ) ) {
			self::fail( 'no se pudo preparar la fila del socio ' . $userId . ' para quitar sus membresías.' );
			return false;
		}
		$member = self::lockMember( $userId );
		if ( null === $member ) {
			self::fail( 'no se pudo bloquear la fila del socio ' . $userId . ' para quitar sus membresías.' );
			return false;
		}

		$grants = self::forUser( $userId, true );
		if ( array() === $grants ) {
			self::rollback();
			self::log( 'el usuario ' . $userId . ' no tiene concesiones vivas que quitar.' );
			return false;
		}

		$before = self::stateOf( $grants, $now );
		$ids    = array();
		foreach ( $grants as $grant ) {
			$data = array( 'updated_at' => $nowSql );
			if ( 'active' === (string) $grant['status'] ) {
				$data['status']  = 'ended';
				$data['ends_at'] = $nowSql;
			} else {
				$data['status']     = 'revoked';
				$data['revoked_at'] = $nowSql;
			}
			if ( false === self::updateGrant( (int) $grant['id'], $data ) ) {
				self::fail( 'no se pudo terminar la concesión ' . (int) $grant['id'] . ' del usuario ' . $userId . '.' );
				return false;
			}
			$ids[] = (int) $grant['id'];
		}

		$frozen = null !== self::date( $member?->frozen_at ?? null );
		if ( ! self::cacheMember( $userId, null, $nowSql, array( 'frozen_at' => null ) ) ) {
			self::fail( 'no se pudo limpiar la caché del socio ' . $userId . ' al quitar sus membresías.' );
			return false;
		}
		if ( ! self::revokeTokens( $userId, $nowSql ) ) {
			self::fail( 'no se pudieron revocar los tokens del carné del usuario ' . $userId . '.' );
			return false;
		}

		$oldLevel = $frozen ? 0 : (int) $before['level_id'];
		$eventId  = self::recordEvent(
			$userId,
			null,
			HWE_Memberships_Events::REMOVED_BY_ADMIN,
			$oldLevel,
			0,
			$actorId > 0 ? 'admin' : 'system',
			$actorId,
			array(
				'reason' => $reason,
				'grants' => $ids,
			),
			$nowSql,
			$oldLevel
		);
		if ( null === $eventId ) {
			self::fail( 'no se pudo registrar la retirada de membresías del usuario ' . $userId . '.' );
			return false;
		}

		if ( ! self::commit() ) {
			self::clearQueue();
			self::rollback();
			self::log( 'no se pudo confirmar la retirada de membresías del usuario ' . $userId . '.' );
			return false;
		}

		self::flush();
		return true;
	}

	// ------------------------------------------------------------------------
	//  Congelación
	// ------------------------------------------------------------------------

	/**
	 * Congela la membresía: el socio deja de tener nivel efectivo y el reloj se detiene (las
	 * fechas se desplazan al descongelar). Revoca los tokens del carné. Exige una concesión
	 * con cobertura vigente o futura; un usuario sin membresía o ya congelado se rechaza.
	 */
	public static function freeze( int $userId, int $actorId ): bool {
		self::clearQueue();

		if ( $userId <= 0 ) {
			return false;
		}

		$now    = self::now();
		$nowSql = self::sqlDate( $now );

		if ( ! self::begin() ) {
			self::log( 'no se pudo abrir la transacción de congelación del usuario ' . $userId . '.' );
			return false;
		}
		if ( ! self::ensureMemberRow( $userId, $nowSql ) ) {
			self::fail( 'no se pudo preparar la fila del socio ' . $userId . ' para congelarlo.' );
			return false;
		}
		$member = self::lockMember( $userId );
		if ( null === $member ) {
			self::fail( 'no se pudo bloquear la fila del socio ' . $userId . ' para congelarlo.' );
			return false;
		}

		if ( null !== self::date( $member?->frozen_at ?? null ) ) {
			self::rollback();
			self::log( 'el usuario ' . $userId . ' ya está congelado.' );
			return false;
		}

		$grants = self::forUser( $userId, true );
		if ( ! self::hasCoverage( $grants, $now ) ) {
			self::rollback();
			self::log( 'el usuario ' . $userId . ' no tiene membresía vigente que congelar.' );
			return false;
		}

		$before   = self::stateOf( $grants, $now );
		$oldLevel = (int) $before['level_id'];

		$updated = self::updateMember(
			$userId,
			array(
				'frozen_at'    => $nowSql,
				'level_id'     => null,
				'covers_until' => null,
				'updated_at'   => $nowSql,
			)
		);
		if ( ! $updated ) {
			self::fail( 'no se pudo congelar al usuario ' . $userId . '.' );
			return false;
		}
		if ( ! self::revokeTokens( $userId, $nowSql ) ) {
			self::fail( 'no se pudieron revocar los tokens del carné del usuario ' . $userId . ' al congelarlo.' );
			return false;
		}

		$eventId = self::recordEvent(
			$userId,
			null,
			HWE_Memberships_Events::FROZEN,
			$oldLevel,
			0,
			$actorId > 0 ? 'admin' : 'system',
			$actorId,
			array( 'frozen_at' => $nowSql ),
			$nowSql,
			0
		);
		if ( null === $eventId ) {
			self::fail( 'no se pudo registrar la congelación del usuario ' . $userId . '.' );
			return false;
		}

		if ( ! self::commit() ) {
			self::clearQueue();
			self::rollback();
			self::log( 'no se pudo confirmar la congelación del usuario ' . $userId . '.' );
			return false;
		}

		self::flush();
		return true;
	}

	/**
	 * Descongela: devuelve a cada concesión no terminada el tiempo que **ella** pasó congelada
	 * (las que ya existían al congelar, toda la congelación; las creadas durante ella, desde su
	 * creación), reancla con `Timeline::shiftSince` la cadena programada para que siga contigua,
	 * acumula los segundos y recalcula el nivel efectivo. Las concesiones vencidas **antes** de
	 * congelar no se resucitan: se cierran, dejan un evento `expired` y encolan
	 * `hwe_membership_expired` (tras el COMMIT, como hará la tarea diaria).
	 */
	public static function unfreeze( int $userId, int $actorId ): bool {
		self::clearQueue();

		if ( $userId <= 0 ) {
			return false;
		}

		$now    = self::now();
		$nowSql = self::sqlDate( $now );

		if ( ! self::begin() ) {
			self::log( 'no se pudo abrir la transacción de descongelación del usuario ' . $userId . '.' );
			return false;
		}
		if ( ! self::ensureMemberRow( $userId, $nowSql ) ) {
			self::fail( 'no se pudo preparar la fila del socio ' . $userId . ' para descongelarlo.' );
			return false;
		}
		$member = self::lockMember( $userId );
		if ( null === $member ) {
			self::fail( 'no se pudo bloquear la fila del socio ' . $userId . ' para descongelarlo.' );
			return false;
		}

		$frozenAt = self::date( $member?->frozen_at ?? null );
		if ( null === $frozenAt ) {
			self::rollback();
			self::log( 'el usuario ' . $userId . ' no está congelado.' );
			return false;
		}

		$seconds = max( 0, $now->getTimestamp() - $frozenAt->getTimestamp() );
		$grants  = self::forUser( $userId, true );
		$before  = $grants;

		$shifted = array();
		$expired = array();

		// Lo que ya estaba vencido al congelar se materializa como terminado, no se desplaza y
		// deja su caducidad en el historial (la tarea diaria ya no lo verá como activo).
		foreach ( $grants as $grant ) {
			$end = $grant['ends_at'] ?? null;
			if ( $end instanceof DateTimeImmutable && $end <= $frozenAt ) {
				$grant['status'] = 'ended';
				$expired[]       = $grant;
			}
			$shifted[] = $grant;
		}

		// Cada concesión solo recupera el tiempo que estuvo congelada: las que ya existían al
		// congelar, toda la congelación; las creadas durante ella, desde su creación.
		$since = array();
		foreach ( $shifted as $index => $grant ) {
			if ( ! in_array( (string) ( $grant['status'] ?? '' ), array( 'active', 'scheduled' ), true ) ) {
				continue;
			}
			$created = $grant['created_at'] ?? null;
			$from    = $created instanceof DateTimeImmutable && $created > $frozenAt ? $created : $frozenAt;
			if ( $from > $now ) {
				$from = $now;
			}
			$own = max( 0, $now->getTimestamp() - $from->getTimestamp() );

			// El campo guarda el tiempo propio de congelación de la concesión; una programada
			// puede desplazarse algo más al reanclarse a la cadena que la precede.
			$since[ (string) $grant['key'] ] = $from;
			$shifted[ $index ]['frozen_seconds'] = (int) ( $grant['frozen_seconds'] ?? 0 ) + $own;
		}

		$shifted = HWE_Memberships_Timeline::shiftSince( $shifted, $since, $now );
		if ( ! self::persistChanged( $before, $shifted, $nowSql ) ) {
			self::fail( 'no se pudieron desplazar las concesiones del usuario ' . $userId . ' al descongelarlo.' );
			return false;
		}

		$after         = self::stateOf( $shifted, $now );
		$frozenSeconds = (int) ( $member?->frozen_seconds ?? 0 ) + $seconds;
		if ( ! self::cacheMember(
			$userId,
			$after,
			$nowSql,
			array(
				'frozen_at'      => null,
				'frozen_seconds' => $frozenSeconds,
			)
		) ) {
			self::fail( 'no se pudo actualizar la caché del socio ' . $userId . ' al descongelarlo.' );
			return false;
		}

		foreach ( $expired as $grant ) {
			$eventId = self::recordEvent(
				$userId,
				(int) $grant['id'],
				HWE_Memberships_Events::EXPIRED,
				0,
				0,
				'system',
				null,
				array(
					'key'       => (string) $grant['key'],
					'level_id'  => (int) $grant['level_id'],
					'ends_at'   => self::sqlDate( $grant['ends_at'] ?? null ),
					'frozen_at' => self::sqlDate( $frozenAt ),
					'reason'    => 'expired_before_freeze',
				),
				$nowSql,
				(int) $grant['level_id']
			);
			if ( null === $eventId ) {
				self::fail( 'no se pudo registrar la caducidad de la concesión ' . (int) $grant['id'] . ' del usuario ' . $userId . '.' );
				return false;
			}
		}

		$eventId = self::recordEvent(
			$userId,
			null,
			HWE_Memberships_Events::UNFROZEN,
			0,
			(int) $after['level_id'],
			$actorId > 0 ? 'admin' : 'system',
			$actorId,
			array( 'frozen_seconds' => $seconds ),
			$nowSql,
			0
		);
		if ( null === $eventId ) {
			self::fail( 'no se pudo registrar la descongelación del usuario ' . $userId . '.' );
			return false;
		}

		if ( ! self::commit() ) {
			self::clearQueue();
			self::rollback();
			self::log( 'no se pudo confirmar la descongelación del usuario ' . $userId . '.' );
			return false;
		}

		self::flush();
		return true;
	}

	// ------------------------------------------------------------------------
	//  Lectura de concesiones (compartida con Members)
	// ------------------------------------------------------------------------

	/**
	 * Concesiones vivas del usuario (`active` o `scheduled`) en el modelo de `Timeline`.
	 * `$lock` añade `FOR UPDATE` y solo debe usarse dentro de una transacción.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function forUser( int $userId, bool $lock = false ): array {
		global $wpdb;

		if ( $userId <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$sql = 'SELECT * FROM ' . HWE_Memberships_Schema::grantsTable()
			. " WHERE user_id = %d AND status IN ('active','scheduled') ORDER BY starts_at ASC, id ASC";
		if ( $lock ) {
			$sql .= ' FOR UPDATE';
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $userId ) );
		$rank = array();
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			$levelId = (int) ( $row->level_id ?? 0 );
			if ( ! array_key_exists( $levelId, $rank ) ) {
				$level         = self::level( $levelId );
				$rank[ $levelId ] = null === $level ? 0 : (int) ( $level['rank'] ?? 0 );
			}
			$out[] = array(
				'id'              => (int) ( $row->id ?? 0 ),
				'key'             => (string) ( $row->idempotency_key ?? '' ),
				'level_id'        => $levelId,
				'rank'            => $rank[ $levelId ],
				'days'            => max( 0, (int) ( $row->duration_days ?? 0 ) ),
				'queued_seconds'  => null === ( $row->queued_seconds ?? null ) ? null : (int) $row->queued_seconds,
				'starts_at'       => self::date( $row->starts_at ?? null ),
				'ends_at'         => self::date( $row->ends_at ?? null ),
				'status'          => (string) ( $row->status ?? '' ),
				'origin'          => (string) ( $row->origin ?? '' ),
				'ref_type'        => null === ( $row->ref_type ?? null ) ? null : (string) $row->ref_type,
				'ref_id'          => null === ( $row->ref_id ?? null ) ? null : (string) $row->ref_id,
				'actor'           => (string) ( $row->actor ?? 'system' ),
				'actor_id'        => null === ( $row->actor_id ?? null ) ? null : (int) $row->actor_id,
				'note'            => null === ( $row->note ?? null ) ? null : (string) $row->note,
				'data'            => $row->data ?? null,
				'frozen_seconds'  => (int) ( $row->frozen_seconds ?? 0 ),
				'created_at'      => self::date( $row->created_at ?? null ),
				'revoked_at'      => $row->revoked_at ?? null,
			);
		}

		return $out;
	}

	// ------------------------------------------------------------------------
	//  Persistencia y transacción
	// ------------------------------------------------------------------------

	/** @param array<string,mixed> $grant */
	private static function insertGrant( int $userId, array $grant, string $nowSql ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			HWE_Memberships_Schema::grantsTable(),
			array(
				'user_id'         => $userId,
				'level_id'        => (int) $grant['level_id'],
				'origin'          => (string) ( $grant['origin'] ?? HWE_Memberships_Grants::ORIGIN_ORDER ),
				'ref_type'        => $grant['ref_type'] ?? null,
				'ref_id'          => $grant['ref_id'] ?? null,
				'idempotency_key' => (string) $grant['key'],
				'duration_days'   => max( 0, (int) ( $grant['days'] ?? 0 ) ),
				'queued_seconds'  => null === ( $grant['queued_seconds'] ?? null ) ? null : (int) $grant['queued_seconds'],
				'starts_at'       => self::sqlDate( $grant['starts_at'] ?? null ),
				'ends_at'         => self::sqlDate( $grant['ends_at'] ?? null ),
				'status'          => (string) ( $grant['status'] ?? 'scheduled' ),
				'actor'           => (string) ( $grant['actor'] ?? 'system' ),
				'actor_id'        => null === ( $grant['actor_id'] ?? null ) ? null : (int) $grant['actor_id'],
				'note'            => $grant['note'] ?? null,
				'data'            => $grant['data'] ?? null,
				'created_at'      => $nowSql,
				'updated_at'      => $nowSql,
			)
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/** @param array<string,mixed> $data */
	private static function updateGrant( int $grantId, array $data ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			HWE_Memberships_Schema::grantsTable(),
			$data,
			array( 'id' => $grantId )
		);
	}

	/**
	 * Persiste únicamente las concesiones que `Timeline` cambió (fechas, estado o cola). Las
	 * que no cambiaron no se tocan. `$skipKey` excluye la fila que la operación ya actualizó.
	 *
	 * @param list<array<string,mixed>> $before
	 * @param list<array<string,mixed>> $after
	 */
	private static function persistChanged( array $before, array $after, string $nowSql, ?string $skipKey = null ): bool {
		$byKey = array();
		foreach ( $after as $grant ) {
			$byKey[ (string) $grant['key'] ] = $grant;
		}

		foreach ( $before as $old ) {
			$key = (string) $old['key'];
			if ( null !== $skipKey && $key === $skipKey ) {
				continue;
			}
			if ( ! isset( $byKey[ $key ] ) ) {
				continue;
			}
			$new  = $byKey[ $key ];
			$data = array();

			if ( self::dateKey( $old['starts_at'] ?? null ) !== self::dateKey( $new['starts_at'] ?? null ) ) {
				$data['starts_at'] = self::sqlDate( $new['starts_at'] ?? null );
			}
			if ( self::dateKey( $old['ends_at'] ?? null ) !== self::dateKey( $new['ends_at'] ?? null ) ) {
				$data['ends_at'] = self::sqlDate( $new['ends_at'] ?? null );
			}
			if ( (string) ( $old['status'] ?? '' ) !== (string) ( $new['status'] ?? '' ) ) {
				$data['status'] = (string) $new['status'];
			}
			if ( self::nullableInt( $old['queued_seconds'] ?? null ) !== self::nullableInt( $new['queued_seconds'] ?? null ) ) {
				$data['queued_seconds'] = self::nullableInt( $new['queued_seconds'] ?? null );
			}
			if ( (int) ( $old['frozen_seconds'] ?? 0 ) !== (int) ( $new['frozen_seconds'] ?? 0 ) ) {
				$data['frozen_seconds'] = (int) ( $new['frozen_seconds'] ?? 0 );
			}

			if ( array() === $data ) {
				continue;
			}
			$data['updated_at'] = $nowSql;
			if ( ! self::updateGrant( (int) $old['id'], $data ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Actualiza la caché de `members` con el nivel efectivo recibido (`null` = sin nivel) y
	 * los campos extra de la operación.
	 *
	 * @param array<string,mixed>|null $state
	 * @param array<string,mixed>      $extra
	 */
	private static function cacheMember( int $userId, ?array $state, string $nowSql, array $extra = array() ): bool {
		$levelId = null === $state ? 0 : (int) ( $state['level_id'] ?? 0 );

		$data = array(
			'level_id'     => $levelId > 0 ? $levelId : null,
			'covers_until' => null === $state ? null : self::sqlDate( $state['ends_at'] ?? null ),
			'updated_at'   => $nowSql,
		) + $extra;

		return self::updateMember( $userId, $data );
	}

	/** @param array<string,mixed> $data */
	private static function updateMember( int $userId, array $data ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			HWE_Memberships_Schema::membersTable(),
			$data,
			array( 'user_id' => $userId )
		);
	}

	private static function ensureMemberRow( int $userId, string $nowSql ): bool {
		global $wpdb;

		$created = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . HWE_Memberships_Schema::membersTable()
				. ' (user_id, updated_at) VALUES (%d, %s)',
				$userId,
				$nowSql
			)
		);

		return false !== $created;
	}

	/** @return object|null */
	private static function lockMember( int $userId ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Memberships_Schema::membersTable() . ' WHERE user_id = %d FOR UPDATE',
				$userId
			)
		);

		return is_object( $row ) ? $row : null;
	}

	private static function grantIdByKey( string $key ): ?int {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . HWE_Memberships_Schema::grantsTable()
				. ' WHERE idempotency_key = %s LIMIT 1',
				$key
			)
		);

		return null === $id ? null : (int) $id;
	}

	/** @return object|null */
	private static function rowByKey( string $key ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Memberships_Schema::grantsTable()
				. ' WHERE idempotency_key = %s LIMIT 1',
				$key
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/** Revoca los tokens del carné todavía válidos del usuario. */
	private static function revokeTokens( int $userId, string $nowSql ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . HWE_Memberships_Schema::cardTokensTable()
				. ' SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL',
				$nowSql,
				$userId
			)
		);

		return false !== $updated;
	}

	/**
	 * Número de socio secuencial. Se incrementa con la fila de la opción bloqueada, así que dos
	 * primeras concesiones simultáneas de usuarios distintos no repiten número.
	 */
	private static function nextMemberNumber(): string {
		global $wpdb;

		if ( ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options ) {
			return '';
		}

		$created = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . $wpdb->options . ' (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				self::MEMBER_SEQUENCE_OPTION,
				'0',
				'no'
			)
		);
		if ( false === $created ) {
			return '';
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = %s FOR UPDATE',
				self::MEMBER_SEQUENCE_OPTION
			)
		);
		if ( ! is_object( $row ) ) {
			return '';
		}

		$next    = max( 1, (int) ( $row->option_value ?? 0 ) + 1 );
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $wpdb->options . ' SET option_value = %s WHERE option_name = %s',
				(string) $next,
				self::MEMBER_SEQUENCE_OPTION
			)
		);
		if ( false === $updated ) {
			return '';
		}

		return str_pad( (string) $next, 6, '0', STR_PAD_LEFT );
	}

	// ------------------------------------------------------------------------
	//  Decisiones y utilidades
	// ------------------------------------------------------------------------

	/** Orígenes permitidos: los cuatro base más los que añada el filtro. */
	private static function origins(): array {
		$listed = function_exists( 'apply_filters' )
			? apply_filters( 'hwe_memberships_grant_origins', self::BASE_ORIGINS )
			: self::BASE_ORIGINS;
		if ( ! is_array( $listed ) ) {
			self::log( 'el filtro hwe_memberships_grant_origins no devolvió un array; se usan los orígenes base.' );
			$listed = self::BASE_ORIGINS;
		}

		$origins = self::BASE_ORIGINS;
		foreach ( $listed as $origin ) {
			if ( ! is_scalar( $origin ) ) {
				continue;
			}
			$origin = self::normalizeOrigin( (string) $origin );
			if ( '' !== $origin ) {
				$origins[] = $origin;
			}
		}

		return array_values( array_unique( $origins ) );
	}

	/**
	 * Evento de una concesión nueva. Los orígenes especiales mandan; para el resto se decide
	 * por el estado efectivo anterior y posterior.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param array<string,mixed> $grant
	 */
	private static function eventType( string $origin, array $before, array $after, array $grant ): string {
		if ( self::ORIGIN_ADMIN === $origin ) {
			return HWE_Memberships_Events::GRANTED_BY_ADMIN;
		}
		if ( self::ORIGIN_REFERRAL_GIFT === $origin ) {
			return HWE_Memberships_Events::REFERRAL_GIFT_GRANTED;
		}

		$beforeLevel = (int) $before['level_id'];
		$afterLevel  = (int) $after['level_id'];
		$beforeRank  = (int) $before['rank'];
		$afterRank   = (int) $after['rank'];

		if ( 0 === $beforeLevel && 'active' === (string) ( $grant['status'] ?? '' ) ) {
			return HWE_Memberships_Events::ACTIVATED;
		}
		if ( $beforeLevel > 0 && (int) $grant['level_id'] === $beforeLevel ) {
			return HWE_Memberships_Events::RENEWED;
		}
		if ( $afterLevel > 0 && $afterRank > $beforeRank ) {
			return HWE_Memberships_Events::UPGRADED;
		}

		return HWE_Memberships_Events::DEFERRED;
	}

	/** @param list<array<string,mixed>> $grants */
	private static function stateOf( array $grants, DateTimeImmutable $at ): array {
		if ( class_exists( 'HWE_Memberships_Members' ) ) {
			return HWE_Memberships_Members::stateFromGrants( $grants, $at );
		}

		return array(
			'level_id'  => 0,
			'rank'      => 0,
			'status'    => 'none',
			'ends_at'   => null,
			'queue'     => array(),
			'grant_key' => '',
		);
	}

	/** ¿Hay alguna concesión con cobertura vigente o futura? Se decide por fechas. */
	private static function hasCoverage( array $grants, DateTimeImmutable $now ): bool {
		foreach ( $grants as $grant ) {
			$end = $grant['ends_at'] ?? null;
			if ( ! $end instanceof DateTimeImmutable || $end > $now ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $data */
	private static function recordEvent(
		int $userId,
		?int $grantId,
		string $type,
		int $levelFrom,
		int $levelTo,
		string $actor,
		?int $actorId,
		array $data,
		string $nowSql,
		int $hookLevelId
	): ?int {
		if ( ! class_exists( 'HWE_Memberships_Events' ) ) {
			return null;
		}

		return HWE_Memberships_Events::record(
			$userId,
			$grantId,
			$type,
			$levelFrom,
			$levelTo,
			$actor,
			$actorId,
			$data,
			$nowSql,
			$hookLevelId
		);
	}

	/** @param array<mixed> $ref @return array{0:?string,1:?string} */
	private static function normalizeRef( array $ref ): array {
		$type = null;
		$id   = null;

		if ( array_key_exists( 'type', $ref ) || array_key_exists( 'id', $ref ) ) {
			$type = $ref['type'] ?? null;
			$id   = $ref['id'] ?? null;
		} elseif ( 1 === count( $ref ) ) {
			$type = array_key_first( $ref );
			$id   = $ref[ $type ];
		}

		$type = is_scalar( $type ) ? strtolower( trim( (string) $type ) ) : '';
		$type = (string) preg_replace( '/[^a-z0-9_\-]/', '', $type );
		$type = '' !== $type ? substr( $type, 0, 32 ) : null;

		$id = is_scalar( $id ) ? trim( (string) $id ) : '';
		$id = '' !== $id ? substr( $id, 0, 64 ) : null;

		return array( $type, $id );
	}

	/**
	 * Separa actor, nota y datos estructurados. Un `actorId` positivo implica administrador.
	 *
	 * @param array<mixed> $data
	 * @return array{0:string,1:?int,2:?string,3:?string}
	 */
	private static function normalizeMeta( array $data, int $actorId ): array {
		$note = null;
		if ( isset( $data['note'] ) && is_scalar( $data['note'] ) ) {
			$note = trim( (string) $data['note'] );
			$note = '' !== $note ? $note : null;
		}
		unset( $data['note'] );

		$actor   = $actorId > 0 ? 'admin' : 'system';
		$actorId = $actorId > 0 ? $actorId : null;
		$json    = array() === $data ? null : self::json( $data );

		return array( $actor, $actorId, $note, $json );
	}

	/** @return array<string,mixed>|null */
	private static function level( int $levelId ): ?array {
		if ( ! class_exists( 'HWE_Memberships_Levels' ) ) {
			return null;
		}
		$level = HWE_Memberships_Levels::get( $levelId );
		return is_array( $level ) ? $level : null;
	}

	/** @param list<array<string,mixed>> $grants @return array<string,mixed>|null */
	private static function byKey( array $grants, string $key ): ?array {
		foreach ( $grants as $grant ) {
			if ( (string) ( $grant['key'] ?? '' ) === $key ) {
				return $grant;
			}
		}
		return null;
	}

	private static function begin(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'START TRANSACTION' );
	}

	private static function commit(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'COMMIT' );
	}

	private static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	/** Aborta: descarta hooks, deshace la transacción y registra el motivo. */
	private static function fail( string $message ): void {
		self::clearQueue();
		self::rollback();
		self::log( $message );
	}

	private static function clearQueue(): void {
		if ( class_exists( 'HWE_Memberships_Events' ) ) {
			HWE_Memberships_Events::clear();
		}
	}

	private static function flush(): void {
		if ( class_exists( 'HWE_Memberships_Events' ) ) {
			HWE_Memberships_Events::flush();
		}
	}

	private static function validUser( int $userId ): bool {
		if ( $userId <= 0 ) {
			return false;
		}
		if ( function_exists( 'get_userdata' ) ) {
			return is_object( get_userdata( $userId ) );
		}
		return true;
	}

	private static function normalizeOrigin( string $origin ): string {
		$origin = strtolower( trim( $origin ) );
		$origin = (string) preg_replace( '/[^a-z0-9_\-]/', '', $origin );
		return substr( $origin, 0, 32 );
	}

	private static function normalizeKey( string $key ): string {
		return substr( trim( $key ), 0, self::KEY_MAX );
	}

	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( '@' . time() );
	}

	private static function sqlDate( mixed $date ): ?string {
		if ( ! $date instanceof DateTimeImmutable ) {
			return null;
		}
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

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

	private static function dateKey( mixed $date ): string {
		return $date instanceof DateTimeImmutable ? self::sqlDate( $date ) : '';
	}

	private static function nullableInt( mixed $value ): ?int {
		return null === $value ? null : (int) $value;
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
