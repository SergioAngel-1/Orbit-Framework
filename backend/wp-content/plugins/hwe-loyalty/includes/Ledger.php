<?php
/**
 * Libro mayor de `hwe-loyalty` (spec §5.1–§5.3): abonos, cargos con consumo FIFO, devolución de
 * cargos, caducidad y lecturas.
 *
 * Reglas que sostiene esta clase:
 *  · **Enteros positivos** en la entrada de cada movimiento; un cargo se guarda con signo
 *    negativo. Puntos ≤ 0, un tipo del lado equivocado, un usuario inexistente o un id ≤ 0 no
 *    escriben nada.
 *  · **Idempotencia por clave**: la clave va en un índice ÚNICO. Un `INSERT` duplicado falla y
 *    la operación devuelve `null`; no hace falta (ni basta) una consulta previa.
 *  · **Saldo nunca negativo**: el cargo comprueba el saldo con la fila bloqueada y, si no
 *    alcanza, vuelve atrás sin escribir.
 *  · **Transacción + bloqueo de fila**: toda operación que mueve puntos abre transacción, crea
 *    la fila de saldo si falta, la bloquea con `SELECT … FOR UPDATE`, escribe el movimiento y
 *    los remanentes, actualiza el saldo y confirma. Cualquier fallo de escritura hace
 *    `ROLLBACK` y registra `error_log('[hwe] loyalty: …')` sin datos personales.
 *  · **Acciones después del COMMIT**: `hwe_loyalty_credited`, `hwe_loyalty_debited` y
 *    `hwe_loyalty_expired` reciben (usuario, puntos, tipo, referencia) y solo se disparan
 *    cuando el movimiento ya está confirmado.
 *  · **Consumo FIFO**: primero los abonos que caducan antes; los que no caducan, al final. La
 *    tabla puente `hwe_loyalty_ledger_consumptions` guarda qué abono consumió cada cargo y
 *    cuánto, para poder devolver exactamente lo mismo.
 *
 * La descripción que ve el cliente NO se guarda aquí: el libro guarda el tipo y `data`
 * estructurada; el texto se compone al leer con el nombre de la unidad vigente. La `note` es la
 * nota libre del administrador.
 *
 * Convención de `$ref`: `array( 'type' => 'order', 'id' => 55 )` o, como atajo, un único par
 * `array( 'order' => 55 )`. Se guarda como referencia tipada (`ref_type` + `ref_id`).
 *
 * Convención de `$data`: se guarda como JSON. Las claves reservadas `actor`, `actor_id` y
 * `note` se extraen a sus columnas y no se duplican dentro del JSON:
 *  · `actor`: `system` (por defecto), `admin` o `user`; un id numérico implica `admin`.
 *  · `note`: nota libre del administrador (solo texto de gestión, nunca la descripción del
 *    movimiento).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Ledger {

	public const ACTION_CREDITED = 'hwe_loyalty_credited';
	public const ACTION_DEBITED  = 'hwe_loyalty_debited';
	public const ACTION_EXPIRED  = 'hwe_loyalty_expired';

	/** Longitud máxima de la clave de idempotencia (la columna es varchar(191)). */
	private const KEY_MAX = 191;

	/** Tope de movimientos por página en `history()`. */
	private const PER_PAGE_MAX = 100;

	// ------------------------------------------------------------------------
	//  Movimientos
	// ------------------------------------------------------------------------

	/**
	 * Abona puntos. Devuelve el id del movimiento o `null` si la clave ya existía (o la entrada
	 * no es válida).
	 *
	 * `$expiryDays === null` usa `loyalty.expiry_days`; `0` significa que este abono no caduca.
	 */
	public static function credit(
		int $userId,
		int $points,
		string $type,
		string $key,
		array $ref = array(),
		?int $expiryDays = null,
		array $data = array()
	): ?int {
		if ( ! self::validUser( $userId ) || $points <= 0 ) {
			self::log( 'abono rechazado para el usuario ' . $userId . ': puntos ' . $points . '.' );
			return null;
		}
		if ( ! HWE_Loyalty_EntryTypes::isCredit( $type ) ) {
			self::log( 'abono rechazado para el usuario ' . $userId . ': el tipo «' . $type . '» no es un abono.' );
			return null;
		}
		$key = self::normalizeKey( $key );
		if ( '' === $key ) {
			self::log( 'abono rechazado para el usuario ' . $userId . ': sin clave de idempotencia.' );
			return null;
		}

		global $wpdb;

		$now       = self::now();
		$expiresAt = self::expiresAt( $expiryDays );
		[ $refType, $refId ] = self::normalizeRef( $ref );
		[ $actor, $actorId, $note, $payload ] = self::normalizeData( $data );

		self::begin();
		if ( ! self::ensureBalanceRow( $userId, $now ) || null === self::lockBalance( $userId ) ) {
			self::rollback();
			self::log( 'no se pudo preparar el saldo del usuario ' . $userId . '.' );
			return null;
		}

		$inserted = $wpdb->insert(
			HWE_Loyalty_Schema::ledgerTable(),
			array(
				'user_id'          => $userId,
				'points'           => $points,
				'type'             => $type,
				'ref_type'         => $refType,
				'ref_id'           => $refId,
				'idempotency_key'  => $key,
				'remaining_points' => $points,
				'expires_at'       => $expiresAt,
				'actor'            => $actor,
				'actor_id'         => $actorId,
				'note'             => $note,
				'data'             => $payload,
				'created_at'       => $now,
			)
		);
		$entryId  = $inserted ? (int) $wpdb->insert_id : 0;

		if ( $entryId <= 0 ) {
			self::rollback();
			if ( null !== self::entryIdByKey( $key ) ) {
				return null; // La clave ya existía: idempotente, no es un error.
			}
			self::log( 'no se pudo insertar el abono «' . $type . '» del usuario ' . $userId . '.' );
			return null;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . HWE_Loyalty_Schema::balancesTable()
				. ' SET balance = balance + %d, earned = earned + %d, updated_at = %s WHERE user_id = %d',
				$points,
				$points,
				$now,
				$userId
			)
		);
		if ( 1 !== (int) $updated ) {
			self::rollback();
			self::log( 'falló la actualización del saldo del usuario ' . $userId . '; abono revertido.' );
			return null;
		}

		if ( ! self::commit() ) {
			self::log( 'no se pudo confirmar el abono «' . $type . '» del usuario ' . $userId . '.' );
			return null;
		}

		do_action( self::ACTION_CREDITED, $userId, $points, $type, array( 'type' => $refType, 'id' => $refId ) );

		return $entryId;
	}

	/**
	 * Carga puntos consumiendo abonos vivos en FIFO. Devuelve el id del cargo o `null` si el
	 * saldo no alcanza o la clave ya existía. Nunca deja saldo negativo.
	 */
	public static function debit(
		int $userId,
		int $points,
		string $type,
		string $key,
		array $ref = array(),
		array $data = array()
	): ?int {
		if ( ! self::validUser( $userId ) || $points <= 0 ) {
			self::log( 'cargo rechazado para el usuario ' . $userId . ': puntos ' . $points . '.' );
			return null;
		}
		if ( ! HWE_Loyalty_EntryTypes::isDebit( $type ) ) {
			self::log( 'cargo rechazado para el usuario ' . $userId . ': el tipo «' . $type . '» no es un cargo.' );
			return null;
		}
		$key = self::normalizeKey( $key );
		if ( '' === $key ) {
			self::log( 'cargo rechazado para el usuario ' . $userId . ': sin clave de idempotencia.' );
			return null;
		}

		global $wpdb;

		$now = self::now();
		[ $refType, $refId ] = self::normalizeRef( $ref );
		[ $actor, $actorId, $note, $payload ] = self::normalizeData( $data );

		self::begin();
		if ( ! self::ensureBalanceRow( $userId, $now ) ) {
			self::rollback();
			self::log( 'no se pudo preparar el saldo del usuario ' . $userId . '.' );
			return null;
		}
		$balance = self::lockBalance( $userId );
		if ( null === $balance ) {
			self::rollback();
			self::log( 'no se pudo bloquear el saldo del usuario ' . $userId . '.' );
			return null;
		}
		if ( (int) $balance->balance < $points ) {
			self::rollback();
			return null; // Saldo insuficiente: no es un error, simplemente no se escribe.
		}

		$credits = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, remaining_points, expires_at FROM ' . HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE user_id = %d AND points > 0 AND remaining_points > 0'
				. ' ORDER BY (expires_at IS NULL) ASC, expires_at ASC, id ASC FOR UPDATE',
				$userId
			)
		);
		$allocation = self::allocate(
			$points,
			array_map( static fn ( $row ): array => (array) $row, is_array( $credits ) ? $credits : array() )
		);
		$allocated  = array_sum( array_column( $allocation, 'points' ) );

		if ( $allocated < $points ) {
			self::rollback();
			self::log(
				'el saldo del usuario ' . $userId . ' no coincide con sus abonos vivos (' . $allocated
				. ' de ' . $points . '); cargo revertido.'
			);
			return null;
		}

		$inserted = $wpdb->insert(
			HWE_Loyalty_Schema::ledgerTable(),
			array(
				'user_id'          => $userId,
				'points'           => -$points,
				'type'             => $type,
				'ref_type'         => $refType,
				'ref_id'           => $refId,
				'idempotency_key'  => $key,
				'remaining_points' => null,
				'expires_at'       => null,
				'actor'            => $actor,
				'actor_id'         => $actorId,
				'note'             => $note,
				'data'             => $payload,
				'created_at'       => $now,
			)
		);
		$entryId  = $inserted ? (int) $wpdb->insert_id : 0;

		if ( $entryId <= 0 ) {
			self::rollback();
			if ( null !== self::entryIdByKey( $key ) ) {
				return null; // La clave ya existía: idempotente.
			}
			self::log( 'no se pudo insertar el cargo «' . $type . '» del usuario ' . $userId . '.' );
			return null;
		}

		foreach ( $allocation as $take ) {
			$consumed = $wpdb->insert(
				HWE_Loyalty_Schema::consumptionsTable(),
				array(
					'debit_id'   => $entryId,
					'credit_id'  => (int) $take['id'],
					'points'     => (int) $take['points'],
					'created_at' => $now,
				)
			);
			if ( ! $consumed ) {
				self::rollback();
				self::log( 'no se pudo registrar el consumo del cargo ' . $entryId . '; cargo revertido.' );
				return null;
			}

			$moved = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . HWE_Loyalty_Schema::ledgerTable()
					. ' SET remaining_points = remaining_points - %d WHERE id = %d AND remaining_points >= %d',
					(int) $take['points'],
					(int) $take['id'],
					(int) $take['points']
				)
			);
			if ( 1 !== (int) $moved ) {
				self::rollback();
				self::log( 'no se pudo mover el remanente del abono ' . (int) $take['id'] . '; cargo revertido.' );
				return null;
			}
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . HWE_Loyalty_Schema::balancesTable()
				. ' SET balance = balance - %d, used = used + %d, updated_at = %s WHERE user_id = %d',
				$points,
				$points,
				$now,
				$userId
			)
		);
		if ( 1 !== (int) $updated ) {
			self::rollback();
			self::log( 'falló la actualización del saldo del usuario ' . $userId . '; cargo revertido.' );
			return null;
		}

		if ( ! self::commit() ) {
			self::log( 'no se pudo confirmar el cargo «' . $type . '» del usuario ' . $userId . '.' );
			return null;
		}

		do_action( self::ACTION_DEBITED, $userId, $points, $type, array( 'type' => $refType, 'id' => $refId ) );

		return $entryId;
	}

	/**
	 * Revierte un cargo reabonando exactamente lo que consumió (spec §5.3).
	 *
	 * La caducidad del nuevo abono es la más lejana de los abonos consumidos, o «no caduca» si
	 * alguno de ellos no caducaba: la tienda no puede hacer perder puntos al cliente por
	 * cancelar. Idempotente por `$refundKey`.
	 */
	public static function reverseDebit( string $debitKey, string $refundKey ): ?int {
		$debitKey  = self::normalizeKey( $debitKey );
		$refundKey = self::normalizeKey( $refundKey );
		if ( '' === $debitKey || '' === $refundKey ) {
			self::log( 'devolución rechazada: falta la clave del cargo o la de la devolución.' );
			return null;
		}

		global $wpdb;

		self::begin();

		$debit = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE idempotency_key = %s AND points < 0 LIMIT 1',
				$debitKey
			)
		);
		if ( ! is_object( $debit ) ) {
			self::rollback();
			self::log( 'no existe el cargo «' . $debitKey . '» que se quiere devolver.' );
			return null;
		}

		if ( null !== self::entryIdByKey( $refundKey ) ) {
			self::rollback();
			return null; // Ya devuelto.
		}

		$userId = (int) $debit->user_id;
		$now    = self::now();

		if ( ! self::ensureBalanceRow( $userId, $now ) ) {
			self::rollback();
			self::log( 'no se pudo preparar el saldo del usuario ' . $userId . ' para la devolución.' );
			return null;
		}
		$balance = self::lockBalance( $userId );
		if ( null === $balance ) {
			self::rollback();
			self::log( 'no se pudo bloquear el saldo del usuario ' . $userId . ' para la devolución.' );
			return null;
		}

		$consumed = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.credit_id, c.points, l.expires_at FROM ' . HWE_Loyalty_Schema::consumptionsTable()
				. ' c INNER JOIN ' . HWE_Loyalty_Schema::ledgerTable() . ' l ON l.id = c.credit_id'
				. ' WHERE c.debit_id = %d',
				(int) $debit->id
			)
		);
		$consumed = array_map( static fn ( $row ): array => (array) $row, is_array( $consumed ) ? $consumed : array() );

		$points = array_sum( array_map( static fn ( array $row ): int => (int) $row['points'], $consumed ) );
		if ( $points <= 0 ) {
			self::rollback();
			self::log( 'el cargo ' . (int) $debit->id . ' no tiene consumos registrados; no se devuelve nada.' );
			return null;
		}

		$expiresAt = self::refundExpiry( $consumed );
		$type      = HWE_Loyalty_EntryTypes::refundFor( (string) $debit->type );

		$inserted = $wpdb->insert(
			HWE_Loyalty_Schema::ledgerTable(),
			array(
				'user_id'          => $userId,
				'points'           => $points,
				'type'             => $type,
				'ref_type'         => $debit->ref_type,
				'ref_id'           => $debit->ref_id,
				'idempotency_key'  => $refundKey,
				'remaining_points' => $points,
				'expires_at'       => $expiresAt,
				'actor'            => 'system',
				'actor_id'         => null,
				'note'             => null,
				'data'             => self::json( array( 'debit' => (int) $debit->id ) ),
				'created_at'       => $now,
			)
		);
		$entryId  = $inserted ? (int) $wpdb->insert_id : 0;

		if ( $entryId <= 0 ) {
			self::rollback();
			if ( null !== self::entryIdByKey( $refundKey ) ) {
				return null; // Otra petición la confirmó primero.
			}
			self::log( 'no se pudo insertar la devolución del cargo ' . (int) $debit->id . '.' );
			return null;
		}

		$newBalance = (int) $balance->balance + $points;
		$newUsed    = max( 0, (int) $balance->used - $points );

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . HWE_Loyalty_Schema::balancesTable()
				. ' SET balance = %d, used = %d, updated_at = %s WHERE user_id = %d',
				$newBalance,
				$newUsed,
				$now,
				$userId
			)
		);
		if ( 1 !== (int) $updated ) {
			self::rollback();
			self::log( 'falló la actualización del saldo del usuario ' . $userId . '; devolución revertida.' );
			return null;
		}

		if ( ! self::commit() ) {
			self::log( 'no se pudo confirmar la devolución del cargo ' . (int) $debit->id . '.' );
			return null;
		}

		do_action(
			self::ACTION_CREDITED,
			$userId,
			$points,
			$type,
			array(
				'type' => null === $debit->ref_type ? null : (string) $debit->ref_type,
				'id'   => null === $debit->ref_id ? null : (string) $debit->ref_id,
			)
		);

		return $entryId;
	}

	// ------------------------------------------------------------------------
	//  Caducidad
	// ------------------------------------------------------------------------

	/**
	 * Reapa los abonos vencidos con remanente, en lotes. Devuelve cuántos abonos procesó.
	 *
	 * Un `expire` por abono, idempotente por `expire:entry:<id>`. Cambiar `expiry_days` solo
	 * afecta a abonos nuevos (la fecha se fijó al abonar).
	 */
	public static function expireDue( int $batch = 200 ): int {
		global $wpdb;

		$batch = max( 1, $batch );
		$now   = self::now();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, user_id, remaining_points FROM ' . HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE points > 0 AND remaining_points > 0 AND expires_at IS NOT NULL AND expires_at <= %s'
				. ' ORDER BY expires_at ASC, id ASC LIMIT %d',
				$now,
				$batch
			)
		);

		$processed = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( self::expireEntry( (int) $row->id, (int) $row->user_id, $now ) ) {
				++$processed;
			}
		}

		return $processed;
	}

	/** Reapa un abono concreto en su propia transacción. `true` si generó el `expire`. */
	private static function expireEntry( int $creditId, int $userId, string $now ): bool {
		global $wpdb;

		self::begin();
		if ( ! self::ensureBalanceRow( $userId, $now ) ) {
			self::rollback();
			self::log( 'no se pudo preparar el saldo del usuario ' . $userId . ' para caducar el abono ' . $creditId . '.' );
			return false;
		}
		$balance = self::lockBalance( $userId );
		if ( null === $balance ) {
			self::rollback();
			self::log( 'no se pudo bloquear el saldo del usuario ' . $userId . ' para caducar el abono ' . $creditId . '.' );
			return false;
		}

		$credit = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT user_id, remaining_points, expires_at FROM ' . HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE id = %d FOR UPDATE',
				$creditId
			)
		);
		if (
			! is_object( $credit )
			|| (int) $credit->remaining_points <= 0
			|| null === $credit->expires_at
			|| (string) $credit->expires_at > $now
		) {
			self::rollback();
			return false;
		}

		$remaining = (int) $credit->remaining_points;
		$key       = 'expire:entry:' . $creditId;

		$inserted = $wpdb->insert(
			HWE_Loyalty_Schema::ledgerTable(),
			array(
				'user_id'          => $userId,
				'points'           => -$remaining,
				'type'             => 'expire',
				'ref_type'         => 'entry',
				'ref_id'           => (string) $creditId,
				'idempotency_key'  => $key,
				'remaining_points' => null,
				'expires_at'       => null,
				'actor'            => 'system',
				'actor_id'         => null,
				'note'             => null,
				'data'             => self::json( array( 'entry' => $creditId ) ),
				'created_at'       => $now,
			)
		);
		if ( ! $inserted ) {
			self::rollback();
			if ( null !== self::entryIdByKey( $key ) ) {
				return false; // Ya reapaado por otra ejecución.
			}
			self::log( 'no se pudo insertar la caducidad del abono ' . $creditId . '.' );
			return false;
		}

		$cleared = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . HWE_Loyalty_Schema::ledgerTable() . ' SET remaining_points = 0 WHERE id = %d',
				$creditId
			)
		);
		if ( 1 !== (int) $cleared ) {
			self::rollback();
			self::log( 'no se pudo vaciar el remanente del abono ' . $creditId . '; caducidad revertida.' );
			return false;
		}

		$newBalance = max( 0, (int) $balance->balance - $remaining );
		$updated    = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . HWE_Loyalty_Schema::balancesTable()
				. ' SET balance = %d, updated_at = %s WHERE user_id = %d',
				$newBalance,
				$now,
				$userId
			)
		);
		if ( 1 !== (int) $updated ) {
			self::rollback();
			self::log( 'falló la actualización del saldo del usuario ' . $userId . '; caducidad revertida.' );
			return false;
		}

		if ( ! self::commit() ) {
			self::log( 'no se pudo confirmar la caducidad del abono ' . $creditId . '.' );
			return false;
		}

		do_action(
			self::ACTION_EXPIRED,
			$userId,
			$remaining,
			'expire',
			array( 'type' => 'entry', 'id' => (string) $creditId )
		);

		return true;
	}

	// ------------------------------------------------------------------------
	//  Lecturas
	// ------------------------------------------------------------------------

	/**
	 * Saldo del usuario. `used` cuenta lo que salió por cargos (canjes, transferencias, ajustes,
	 * anulaciones); las caducidades bajan el saldo sin contarse como «uso».
	 *
	 * @return array{balance:int,earned:int,used:int}
	 */
	public static function balance( int $userId ): array {
		global $wpdb;

		if ( $userId <= 0 ) {
			return array( 'balance' => 0, 'earned' => 0, 'used' => 0 );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT balance, earned, used FROM ' . HWE_Loyalty_Schema::balancesTable() . ' WHERE user_id = %d',
				$userId
			)
		);

		return array(
			'balance' => is_object( $row ) ? (int) $row->balance : 0,
			'earned'  => is_object( $row ) ? (int) $row->earned : 0,
			'used'    => is_object( $row ) ? (int) $row->used : 0,
		);
	}

	/**
	 * Puntos que caducan dentro de `$days` y la fecha más próxima. Sin nada próximo, ceros y
	 * `null`. Los ya vencidos no cuentan: los reapa la tarea diaria.
	 *
	 * @return array{points:int,date:?string}
	 */
	public static function expiringSoon( int $userId, int $days = 30 ): array {
		global $wpdb;

		if ( $userId <= 0 || $days <= 0 ) {
			return array( 'points' => 0, 'date' => null );
		}

		$from  = self::now();
		$until = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(remaining_points), 0) AS points, MIN(expires_at) AS date FROM '
				. HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE user_id = %d AND points > 0 AND remaining_points > 0'
				. ' AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s',
				$userId,
				$from,
				$until
			)
		);

		return array(
			'points' => is_object( $row ) ? (int) $row->points : 0,
			'date'   => is_object( $row ) ? self::iso( $row->date ) : null,
		);
	}

	/**
	 * Historial paginado, del más nuevo al más antiguo.
	 *
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public static function history( int $userId, int $page, int $perPage ): array {
		global $wpdb;

		if ( $userId <= 0 ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$page    = max( 1, $page );
		$perPage = min( self::PER_PAGE_MAX, max( 1, $perPage ) );
		$offset  = ( $page - 1 ) * $perPage;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . HWE_Loyalty_Schema::ledgerTable() . ' WHERE user_id = %d',
				$userId
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$userId,
				$perPage,
				$offset
			)
		);

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row   = (array) $row;
			$items[] = array(
				'id'               => (int) $row['id'],
				'type'             => (string) $row['type'],
				'points'           => (int) $row['points'],
				'remaining_points' => null === $row['remaining_points'] ? null : (int) $row['remaining_points'],
				'ref'              => array(
					'type' => null === $row['ref_type'] ? null : (string) $row['ref_type'],
					'id'   => null === $row['ref_id'] ? null : (string) $row['ref_id'],
				),
				'data'             => self::decodeData( $row['data'] ?? null ),
				'note'             => null === $row['note'] ? null : (string) $row['note'],
				'created_at'       => self::iso( $row['created_at'] ?? null ),
				'expires_at'       => self::iso( $row['expires_at'] ?? null ),
			);
		}

		return array( 'items' => $items, 'total' => $total );
	}

	// ------------------------------------------------------------------------
	//  Lógica pura (sin SQL)
	// ------------------------------------------------------------------------

	/**
	 * Reparte un cargo entre abonos vivos: primero los que caducan antes, los que no caducan al
	 * final; a igual caducidad, por id (el orden de creación). Salta remanentes vacíos y no
	 * inventa puntos: si no alcanza, devuelve lo que haya.
	 *
	 * @param list<array{id:int|string,remaining_points:int|string,expires_at:?string}> $credits
	 * @return list<array{id:int,points:int}>
	 */
	public static function allocate( int $points, array $credits ): array {
		if ( $points <= 0 ) {
			return array();
		}

		usort(
			$credits,
			static function ( array $left, array $right ): int {
				$leftExpiry  = $left['expires_at'] ?? null;
				$rightExpiry = $right['expires_at'] ?? null;

				if ( null === $leftExpiry && null === $rightExpiry ) {
					return (int) $left['id'] <=> (int) $right['id'];
				}
				if ( null === $leftExpiry ) {
					return 1;
				}
				if ( null === $rightExpiry ) {
					return -1;
				}
				$byDate = strcmp( (string) $leftExpiry, (string) $rightExpiry );
				return 0 !== $byDate ? $byDate : ( (int) $left['id'] <=> (int) $right['id'] );
			}
		);

		$remaining = $points;
		$allocation = array();

		foreach ( $credits as $credit ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$available = (int) ( $credit['remaining_points'] ?? 0 );
			if ( $available <= 0 ) {
				continue;
			}
			$take         = min( $available, $remaining );
			$allocation[] = array( 'id' => (int) $credit['id'], 'points' => $take );
			$remaining   -= $take;
		}

		return $allocation;
	}

	/**
	 * Caducidad de una devolución: la más lejana de los abonos consumidos, o `null` (no caduca)
	 * si alguno de ellos no caducaba (spec §5.3).
	 *
	 * @param list<array{expires_at:?string}> $consumed
	 */
	public static function refundExpiry( array $consumed ): ?string {
		$furthest = null;

		foreach ( $consumed as $row ) {
			$expiry = $row['expires_at'] ?? null;
			if ( null === $expiry || '' === $expiry ) {
				return null;
			}
			if ( null === $furthest || strcmp( (string) $expiry, $furthest ) > 0 ) {
				$furthest = (string) $expiry;
			}
		}

		return $furthest;
	}

	// ------------------------------------------------------------------------
	//  Transacción y utilidades
	// ------------------------------------------------------------------------

	private static function begin(): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	private static function commit(): bool {
		global $wpdb;
		return false !== $wpdb->query( 'COMMIT' );
	}

	private static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}

	/** Crea la fila de saldo si falta. Va antes del bloqueo para que siempre exista que bloquear. */
	private static function ensureBalanceRow( int $userId, string $now ): bool {
		global $wpdb;

		$created = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . HWE_Loyalty_Schema::balancesTable()
				. ' (user_id, balance, earned, used, updated_at) VALUES (%d, 0, 0, 0, %s)',
				$userId,
				$now
			)
		);

		return false !== $created;
	}

	/** Bloquea la fila de saldo del usuario dentro de la transacción en curso. */
	private static function lockBalance( int $userId ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT balance, earned, used FROM ' . HWE_Loyalty_Schema::balancesTable()
				. ' WHERE user_id = %d FOR UPDATE',
				$userId
			)
		);

		return is_object( $row ) ? $row : null;
	}

	/** Id del movimiento con esa clave, o `null`. Solo se usa tras un INSERT fallido. */
	private static function entryIdByKey( string $key ): ?int {
		global $wpdb;

		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . HWE_Loyalty_Schema::ledgerTable()
				. ' WHERE idempotency_key = %s LIMIT 1',
				$key
			)
		);

		return null === $id ? null : (int) $id;
	}

	/** Caducidad del abono: la del ajuste si no se pide otra; `0` días = no caduca. */
	private static function expiresAt( ?int $expiryDays ): ?string {
		if ( null === $expiryDays ) {
			$settings   = HWE_Loyalty_Settings::get();
			$expiryDays = (int) ( $settings['expiry_days'] ?? 0 );
		}

		if ( $expiryDays <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', time() + $expiryDays * DAY_IN_SECONDS );
	}

	/**
	 * Referencia tipada. Acepta `['type'=>…,'id'=>…]` o un único par `['order'=>55]`.
	 *
	 * @param array<mixed> $ref
	 * @return array{0:?string,1:?string}
	 */
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
	 * Separa actor, nota y datos estructurados.
	 *
	 * @param array<mixed> $data
	 * @return array{0:string,1:?int,2:?string,3:?string}
	 */
	private static function normalizeData( array $data ): array {
		$actor   = 'system';
		$actorId = null;
		$note    = null;

		if ( isset( $data['note'] ) && is_scalar( $data['note'] ) ) {
			$note = trim( (string) $data['note'] );
			$note = '' !== $note ? $note : null;
		}

		if ( isset( $data['actor_id'] ) && (int) $data['actor_id'] > 0 ) {
			$actor   = 'admin';
			$actorId = (int) $data['actor_id'];
		}

		if ( isset( $data['actor'] ) && is_scalar( $data['actor'] ) ) {
			$raw = $data['actor'];
			if ( is_int( $raw ) || ctype_digit( (string) $raw ) ) {
				$actor   = 'admin';
				$actorId = (int) $raw;
			} elseif ( in_array( (string) $raw, array( 'system', 'admin', 'user' ), true ) ) {
				$actor = (string) $raw;
			}
		}

		unset( $data['actor'], $data['actor_id'], $data['note'] );

		return array( $actor, $actorId, $note, array() === $data ? null : self::json( $data ) );
	}

	/** Puntos enteros y usuario existente. Un id inexistente no escribe (prepara el Foco 4). */
	private static function validUser( int $userId ): bool {
		if ( $userId <= 0 ) {
			return false;
		}
		if ( function_exists( 'get_userdata' ) ) {
			return false !== get_userdata( $userId );
		}
		return true;
	}

	private static function normalizeKey( string $key ): string {
		return substr( trim( $key ), 0, self::KEY_MAX );
	}

	/** @param array<mixed> $data */
	private static function json( array $data ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data );
		return is_string( $json ) ? $json : '{}';
	}

	/** @return array<string,mixed> */
	private static function decodeData( mixed $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Fecha UTC del libro (`Y-m-d H:i:s`) en ISO 8601, o `null`. */
	private static function iso( mixed $mysql ): ?string {
		if ( ! is_string( $mysql ) || '' === $mysql ) {
			return null;
		}
		$timestamp = strtotime( $mysql . ' UTC' );
		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s', time() );
	}

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: ' . $message );
	}
}
