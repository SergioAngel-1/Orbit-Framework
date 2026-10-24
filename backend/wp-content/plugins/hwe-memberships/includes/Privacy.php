<?php
/**
 * Privacidad del módulo: exportador y eliminador de datos personales de WordPress, y limpieza
 * cuando se elimina una cuenta (`delete_user`).
 *
 * Criterio (spec §14, el mismo que `HWE_Loyalty_Privacy`):
 *  · El **exportador** entrega solo los datos del propio sujeto: estado del socio, sus
 *    concesiones y su historial. Nunca emails, logins ni ids de terceros; de una concesión se
 *    exporta la referencia al pedido (contabilidad), pero no los datos estructurados en bruto
 *    del origen, que pueden identificar a otras cuentas.
 *  · El **eliminador** conserva las concesiones de origen pedido y el historial ligado a ellas
 *    porque son contabilidad de pedidos: vacía notas y datos libres y desvincula el actor
 *    cuando apunta al propio sujeto. El resto de concesiones y eventos del usuario se borran,
 *    igual que los tokens del carné y la fila de `members` (el número de socio no se reutiliza:
 *    el contador no se toca).
 *  · El borrado de datos personales **termina la membresía**: las concesiones de pedido que
 *    sigan `active` o `scheduled` se revocan en la misma transacción (`revoked_at = ahora` y,
 *    si estaban activas, `ends_at = ahora`), sin hooks ni eventos nuevos. El carné y la fila
 *    del socio desaparecen, y la tarea diaria no debe recrearlos para una cuenta borrada o
 *    anonimizada. Mismo criterio que `loyalty` al retirar el saldo.
 *  · Al **eliminar la cuenta** se aplica el mismo criterio. El `reassign` de WordPress se
 *    ignora a propósito: una membresía no se hereda.
 *
 * Toda escritura va en una transacción con la fila del socio bloqueada (`SELECT … FOR UPDATE`).
 * Si la fila aún no existe, el bloqueo de hueco de InnoDB impide que una concesión concurrente
 * la cree a mitad de la limpieza. Las dos herramientas procesan por lotes (nunca cargan el
 * historial entero en memoria) y son idempotentes: repetir la pasada no borra ni duplica nada.
 * Los logs llevan solo ids.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Privacy {

	/** Id del exportador y del eliminador en las herramientas de privacidad de WordPress. */
	public const EXPORTER_ID = 'hwe-memberships';
	public const ERASER_ID   = 'hwe-memberships';

	/** Grupo que ve el administrador en la exportación. */
	public const GROUP_ID    = 'hwe-memberships';
	public const GROUP_LABEL = 'Membresías';

	/** Elementos por página del exportador y por lote del eliminador. */
	public const BATCH = 100;

	/** Origen cuyas concesiones se conservan anonimizadas por ser contabilidad de pedidos. */
	private const ORIGIN_ORDER = 'order';

	/** Aviso al administrador cuando una escritura falla y la página se deshace. */
	private const RETRY_MESSAGE = 'No se pudo completar el borrado; vuelve a intentarlo.';

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'registerExporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'registerEraser' ) );
		add_action( 'delete_user', array( self::class, 'onDeleteUser' ), 10, 3 );
	}

	// ------------------------------------------------------------------------
	//  Registro en las herramientas de WordPress
	// ------------------------------------------------------------------------

	/** @param array<string,array<string,mixed>> $exporters */
	public static function registerExporter( array $exporters ): array {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => self::GROUP_LABEL,
			'callback'               => array( self::class, 'exportPersonalData' ),
		);

		return $exporters;
	}

	/** @param array<string,array<string,mixed>> $erasers */
	public static function registerEraser( array $erasers ): array {
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => self::GROUP_LABEL,
			'callback'             => array( self::class, 'erasePersonalData' ),
		);

		return $erasers;
	}

	// ------------------------------------------------------------------------
	//  Exportador
	// ------------------------------------------------------------------------

	/**
	 * Exporta el estado del socio, sus concesiones y su historial, paginando `BATCH` elementos
	 * en `BATCH`. La primera página incluye además el resumen del estado. El orden es estable
	 * (concesiones primero, luego eventos) para que dos páginas no repitan ningún elemento.
	 *
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public static function exportPersonalData( string $email, int $page = 1 ): array {
		$userId = self::userIdByEmail( $email );
		if ( $userId <= 0 ) {
			return array( 'data' => array(), 'done' => true );
		}

		$page = max( 1, $page );

		$grantsTotal = self::countRows( HWE_Memberships_Schema::grantsTable(), $userId, 'las concesiones' );
		$eventsTotal = self::countRows( HWE_Memberships_Schema::eventsTable(), $userId, 'el historial' );
		$total       = $grantsTotal + $eventsTotal;
		$offset      = ( $page - 1 ) * self::BATCH;

		$items = array();
		if ( 1 === $page ) {
			$items[] = self::summaryItem( $userId );
		}

		if ( $offset < $grantsTotal ) {
			$limit = min( self::BATCH, $grantsTotal - $offset );
			foreach ( self::rows( HWE_Memberships_Schema::grantsTable(), $userId, $offset, $limit, 'las concesiones' ) as $row ) {
				$items[] = self::grantItem( $row );
			}
		}

		if ( $offset + self::BATCH > $grantsTotal ) {
			$eventOffset = max( 0, $offset - $grantsTotal );
			$eventEnd    = min( $eventsTotal, $offset + self::BATCH - $grantsTotal );
			$limit       = $eventEnd - $eventOffset;
			if ( $limit > 0 ) {
				foreach ( self::rows( HWE_Memberships_Schema::eventsTable(), $userId, $eventOffset, $limit, 'el historial' ) as $row ) {
					$items[] = self::eventItem( $row );
				}
			}
		}

		return array(
			'data' => $items,
			'done' => $offset + self::BATCH >= $total,
		);
	}

	/** @return array<string,mixed> */
	private static function summaryItem( int $userId ): array {
		$state = HWE_Memberships_Members::state( $userId );
		$data  = array();

		$number = trim( (string) ( $state['member_number'] ?? '' ) );
		if ( '' !== $number ) {
			$data[] = self::datum( 'Número de socio', $number );
		}

		$since = $state['member_since'] ?? null;
		if ( $since instanceof DateTimeImmutable ) {
			$data[] = self::datum( 'Socio desde (UTC)', self::dateIso( $since ) );
		}

		$data[] = self::datum( 'Estado', self::stateLabel( (string) ( $state['status'] ?? '' ) ) );

		$levelName = self::levelName( (int) ( $state['level_id'] ?? 0 ) );
		if ( '' !== $levelName ) {
			$data[] = self::datum( 'Nivel', $levelName );
		}

		$ends = $state['ends_at'] ?? null;
		if ( $ends instanceof DateTimeImmutable ) {
			$data[] = self::datum( 'Fin de la cobertura (UTC)', self::dateIso( $ends ) );
		}

		$queued = self::queueNames( $state['queue'] ?? array() );
		if ( array() !== $queued ) {
			$data[] = self::datum( 'Niveles en cola', implode( ', ', $queued ) );
		}

		return self::item( 'summary', $userId, $data );
	}

	/**
	 * Una concesión. No se exporta el `data` crudo (puede llevar ids o datos de terceros) ni el
	 * `actor_id`; de la referencia solo se muestra el pedido, que es contabilidad del sujeto.
	 *
	 * @return array<string,mixed>
	 */
	private static function grantItem( object $row ): array {
		$data      = array();
		$levelName = self::levelName( (int) ( $row->level_id ?? 0 ) );
		if ( '' !== $levelName ) {
			$data[] = self::datum( 'Nivel', $levelName );
		}

		$data[] = self::datum( 'Origen', (string) ( $row->origin ?? '' ) );
		$data[] = self::datum( 'Estado', self::grantStatusLabel( (string) ( $row->status ?? '' ) ) );
		$data[] = self::datum( 'Duración (días)', (string) max( 0, (int) ( $row->duration_days ?? 0 ) ) );

		$starts = self::iso( $row->starts_at ?? null );
		if ( null !== $starts ) {
			$data[] = self::datum( 'Inicio (UTC)', $starts );
		}

		$ends = self::iso( $row->ends_at ?? null );
		if ( null !== $ends ) {
			$data[] = self::datum( 'Fin (UTC)', $ends );
		}

		if ( self::ORIGIN_ORDER === (string) ( $row->ref_type ?? '' ) ) {
			$refId = is_scalar( $row->ref_id ?? null ) ? trim( (string) $row->ref_id ) : '';
			if ( '' !== $refId ) {
				$data[] = self::datum( 'Pedido', $refId );
			}
		}

		$note = is_scalar( $row->note ?? null ) ? trim( (string) $row->note ) : '';
		if ( '' !== $note ) {
			$data[] = self::datum( 'Nota', $note );
		}

		$created = self::iso( $row->created_at ?? null );
		if ( null !== $created ) {
			$data[] = self::datum( 'Creada (UTC)', $created );
		}

		return self::item( 'grant', (int) ( $row->id ?? 0 ), $data );
	}

	/**
	 * Un evento del historial: fecha, tipo, niveles y actor. El `data` estructurado no se
	 * exporta porque puede contener claves del origen con ids de otras cuentas.
	 *
	 * @return array<string,mixed>
	 */
	private static function eventItem( object $row ): array {
		$data = array();

		$created = self::iso( $row->created_at ?? null );
		if ( null !== $created ) {
			$data[] = self::datum( 'Fecha (UTC)', $created );
		}

		$data[] = self::datum( 'Tipo', self::eventTypeLabel( (string) ( $row->type ?? '' ) ) );

		$from = self::levelName( (int) ( $row->level_from ?? 0 ) );
		if ( '' !== $from ) {
			$data[] = self::datum( 'Nivel anterior', $from );
		}

		$to = self::levelName( (int) ( $row->level_to ?? 0 ) );
		if ( '' !== $to ) {
			$data[] = self::datum( 'Nivel nuevo', $to );
		}

		$actor = self::actorLabel( (string) ( $row->actor ?? '' ) );
		if ( '' !== $actor ) {
			$data[] = self::datum( 'Actor', $actor );
		}

		return self::item( 'event', (int) ( $row->id ?? 0 ), $data );
	}

	// ------------------------------------------------------------------------
	//  Eliminador (la cuenta puede seguir existiendo)
	// ------------------------------------------------------------------------

	/**
	 * Anonimiza los datos personales del usuario dueño de `$email` sin borrar la contabilidad.
	 * Cada llamada limpia hasta `BATCH` elementos pendientes de cada tipo y devuelve
	 * `done: false` mientras queden; WordPress vuelve a llamar con la página siguiente. Las
	 * concesiones de pedido que sigan vivas se revocan: el borrado de datos termina la
	 * membresía.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public static function erasePersonalData( string $email, int $page = 1 ): array {
		$userId = self::userIdByEmail( $email );
		if ( $userId <= 0 ) {
			return self::eraseResult( false, false, array(), true );
		}

		if ( ! self::begin() ) {
			self::log( 'eliminador: no se pudo abrir la transacción del usuario ' . $userId . '.' );
			return self::eraseResult( false, false, array( self::RETRY_MESSAGE ), false );
		}
		if ( ! self::lockMember( $userId ) ) {
			self::rollback();
			return self::eraseResult( false, false, array( self::RETRY_MESSAGE ), false );
		}

		$removed = false;
		$failed  = false;

		foreach ( self::cleanupSteps( $userId ) as $step ) {
			$changed = $step( self::BATCH );
			if ( null === $changed ) {
				$failed = true;
				break;
			}
			$removed = $removed || $changed > 0;
		}

		$tokensRemoved = 0;
		$memberRemoved = 0;

		if ( ! $failed ) {
			$tokensRemoved = self::deleteTokens( $userId );
			$failed        = null === $tokensRemoved;
		}
		if ( ! $failed ) {
			$memberRemoved = self::deleteMemberRow( $userId );
			$failed        = null === $memberRemoved;
		}

		$removed = $removed || (int) $tokensRemoved > 0 || (int) $memberRemoved > 0;

		$pending = self::pendingAnonymizations( $userId );
		if ( null === $pending ) {
			$failed  = true;
			$pending = 0;
		}
		$retained = self::countOrderGrants( $userId ) > 0;
		$messages = self::messages( $retained, (int) $tokensRemoved > 0 || (int) $memberRemoved > 0 );

		if ( $failed ) {
			self::rollback();
			$messages[] = self::RETRY_MESSAGE;
			self::log( 'eliminador: se deshace la página del usuario ' . $userId . ' por un fallo de escritura.' );
			return self::eraseResult( false, $retained, $messages, false );
		}

		if ( ! self::commit() ) {
			self::rollback();
			$messages[] = self::RETRY_MESSAGE;
			self::log( 'eliminador: no se pudo confirmar la página del usuario ' . $userId . '.' );
			return self::eraseResult( false, $retained, $messages, false );
		}

		self::log( 'eliminador: usuario ' . $userId . ' (página ' . max( 1, $page ) . '), pendientes ' . $pending . '.' );

		return self::eraseResult( $removed, $retained, $messages, 0 === $pending );
	}

	// ------------------------------------------------------------------------
	//  Cuenta eliminada
	// ------------------------------------------------------------------------

	/**
	 * Limpieza al eliminar una cuenta. WordPress llama a este método en `delete_user`, antes de
	 * borrar la cuenta y con el `reassign` que haya elegido el administrador: aquí se ignora a
	 * propósito, porque una membresía no se hereda.
	 *
	 * @param int         $userId   Cuenta que WordPress va a eliminar.
	 * @param mixed       $reassign Usuario de reasignación de contenidos (se ignora).
	 * @param object|null $user     Objeto de la cuenta (no se usa).
	 */
	public static function onDeleteUser( int $userId, mixed $reassign = null, mixed $user = null ): void {
		self::deleteUserData( $userId );
	}

	/**
	 * Anonimiza la contabilidad de pedido (revocando las concesiones que sigan vivas), borra el
	 * resto de concesiones y su historial, y retira el carné (tokens y fila de `members`). No
	 * reinicia el contador de números de socio. Idempotente: repetirlo no cambia nada.
	 */
	public static function deleteUserData( int $userId ): void {
		if ( $userId <= 0 ) {
			return;
		}

		if ( ! self::begin() ) {
			self::log( 'cuenta eliminada: no se pudo abrir la transacción del usuario ' . $userId . '.' );
			return;
		}
		if ( ! self::lockMember( $userId ) ) {
			self::rollback();
			return;
		}

		$counts = array(
			'grants_anonymized' => 0,
			'events_anonymized' => 0,
			'events_deleted'    => 0,
			'grants_deleted'    => 0,
		);

		foreach ( self::cleanupSteps( $userId ) as $key => $step ) {
			$total = self::drain( $step );
			if ( null === $total ) {
				self::rollback();
				self::log( 'cuenta eliminada: se deshace la limpieza del usuario ' . $userId . ' por un fallo de escritura.' );
				return;
			}
			$counts[ $key ] = $total;
		}

		$tokens = self::deleteTokens( $userId );
		if ( null === $tokens ) {
			self::rollback();
			self::log( 'cuenta eliminada: no se pudieron borrar los tokens del usuario ' . $userId . '.' );
			return;
		}

		$member = self::deleteMemberRow( $userId );
		if ( null === $member ) {
			self::rollback();
			self::log( 'cuenta eliminada: no se pudo borrar la fila del socio ' . $userId . '.' );
			return;
		}

		if ( ! self::commit() ) {
			self::rollback();
			self::log( 'cuenta eliminada: no se pudo confirmar la limpieza del usuario ' . $userId . '.' );
			return;
		}

		$pending = self::pendingAnonymizations( $userId );

		self::log(
			'cuenta eliminada: usuario ' . $userId
			. ', concesiones anonimizadas ' . $counts['grants_anonymized']
			. ', eventos anonimizados ' . $counts['events_anonymized']
			. ', eventos borrados ' . $counts['events_deleted']
			. ', concesiones borradas ' . $counts['grants_deleted']
			. ', tokens ' . $tokens
			. ', fila del socio ' . ( $member > 0 ? 'borrada' : 'sin fila' )
			. ', pendientes ' . ( null === $pending ? 'desconocido' : (string) $pending ) . '.'
		);

		if ( null !== $pending && $pending > 0 ) {
			// El hook se ejecuta una sola vez; si quedara algo, la próxima pasada del eliminador
			// de privacidad (o una nueva llamada) lo termina. Se registra con ids, sin PII.
			self::log( 'cuenta eliminada: quedaron ' . $pending . ' elementos sin anonimizar del usuario ' . $userId . '.' );
		}
	}

	// ------------------------------------------------------------------------
	//  Lotes y escrituras
	// ------------------------------------------------------------------------

	/**
	 * Pasos de limpieza en el orden en que deben ejecutarse. Devolver `null` significa fallo y
	 * obliga a deshacer la transacción.
	 *
	 * @return array<string,callable(int):?int>
	 */
	private static function cleanupSteps( int $userId ): array {
		return array(
			'grants_anonymized' => static fn ( int $limit ): ?int => self::anonymizeOrderGrantsBatch( $userId, $limit ),
			'events_anonymized' => static fn ( int $limit ): ?int => self::anonymizeOrderEventsBatch( $userId, $limit ),
			'events_deleted'    => static fn ( int $limit ): ?int => self::deleteOtherEventsBatch( $userId, $limit ),
			'grants_deleted'    => static fn ( int $limit ): ?int => self::deleteOtherGrantsBatch( $userId, $limit ),
		);
	}

	/** Ejecuta un paso por lotes hasta que no queden filas pendientes. `null` si falla. */
	private static function drain( callable $step ): ?int {
		$total = 0;

		while ( true ) {
			$changed = $step( self::BATCH );
			if ( null === $changed ) {
				return null;
			}
			if ( $changed <= 0 ) {
				return $total;
			}
			$total += $changed;
		}
	}

	/**
	 * Anonimiza hasta `$limit` concesiones de origen pedido y **cierra las que siguen vivas**:
	 * vacía nota y datos, desvincula el actor propio y, si están `active` o `scheduled`, las
	 * deja `revoked` con `revoked_at = ahora` (las activas además `ends_at = ahora`). No
	 * registra eventos ni dispara hooks: el sujeto ya no tiene historial. Las terminadas
	 * conservan su estado.
	 */
	private static function anonymizeOrderGrantsBatch( int $userId, int $limit ): ?int {
		global $wpdb;

		$table = HWE_Memberships_Schema::grantsTable();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, actor_id FROM {$table} WHERE user_id = %d AND origin = '" . self::ORIGIN_ORDER . "'"
				. " AND (note IS NOT NULL OR data IS NOT NULL OR actor_id = %d OR status IN ('active','scheduled')) ORDER BY id ASC LIMIT %d",
				$userId,
				$userId,
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			self::log( 'no se pudieron leer las concesiones a anonimizar del usuario ' . $userId . '.' );
			return null;
		}

		$ids = self::idsOf( $rows );
		if ( array() === $ids ) {
			return 0;
		}

		$list    = implode( ',', $ids );
		$changed = 0;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET note = NULL, data = NULL WHERE id IN ({$list}) AND user_id = %d",
				$userId
			)
		);
		if ( false === $updated ) {
			self::log( 'no se pudieron anonimizar las concesiones del usuario ' . $userId . '.' );
			return null;
		}
		$changed += (int) $updated;

		$actors = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET actor_id = NULL WHERE id IN ({$list}) AND actor_id = %d",
				$userId
			)
		);
		if ( false === $actors ) {
			self::log( 'no se pudo desvincular el actor de las concesiones del usuario ' . $userId . '.' );
			return null;
		}
		$changed += (int) $actors;

		// El borrado de datos termina la membresía: nada conservado puede seguir vivo o la tarea
		// diaria recrearía la fila del socio y su historial. Sin hooks ni eventos nuevos.
		$now = self::nowSql();

		$revoked = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'revoked', revoked_at = %s, ends_at = %s, queued_seconds = NULL, updated_at = %s"
				. " WHERE id IN ({$list}) AND status = 'active'",
				$now,
				$now,
				$now
			)
		);
		if ( false === $revoked ) {
			self::log( 'no se pudieron revocar las concesiones activas del usuario ' . $userId . '.' );
			return null;
		}
		$changed += (int) $revoked;

		$revoked = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'revoked', revoked_at = %s, updated_at = %s"
				. " WHERE id IN ({$list}) AND status = 'scheduled'",
				$now,
				$now
			)
		);
		if ( false === $revoked ) {
			self::log( 'no se pudieron revocar las concesiones programadas del usuario ' . $userId . '.' );
			return null;
		}

		return $changed + (int) $revoked;
	}

	/**
	 * Anonimiza hasta `$limit` eventos ligados a concesiones de pedido del usuario: vacía sus
	 * datos y desvincula el actor cuando es el propio sujeto.
	 */
	private static function anonymizeOrderEventsBatch( int $userId, int $limit ): ?int {
		global $wpdb;

		$events = HWE_Memberships_Schema::eventsTable();
		$grants = HWE_Memberships_Schema::grantsTable();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.actor_id FROM {$events} e"
				. ' WHERE e.user_id = %d'
				. " AND e.grant_id IN (SELECT g.id FROM {$grants} g WHERE g.user_id = %d AND g.origin = '" . self::ORIGIN_ORDER . "')"
				. ' AND (e.data IS NOT NULL OR e.actor_id = %d) ORDER BY e.id ASC LIMIT %d',
				$userId,
				$userId,
				$userId,
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			self::log( 'no se pudo leer el historial a anonimizar del usuario ' . $userId . '.' );
			return null;
		}

		$ids = self::idsOf( $rows );
		if ( array() === $ids ) {
			return 0;
		}

		$list    = implode( ',', $ids );
		$changed = 0;

		$updated = $wpdb->query( "UPDATE {$events} SET data = NULL WHERE id IN ({$list})" );
		if ( false === $updated ) {
			self::log( 'no se pudo anonimizar el historial del usuario ' . $userId . '.' );
			return null;
		}
		$changed += (int) $updated;

		$actors = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$events} SET actor_id = NULL WHERE id IN ({$list}) AND actor_id = %d",
				$userId
			)
		);
		if ( false === $actors ) {
			self::log( 'no se pudo desvincular el actor del historial del usuario ' . $userId . '.' );
			return null;
		}

		return $changed + (int) $actors;
	}

	/** Borra hasta `$limit` eventos que no están ligados a una concesión de pedido. */
	private static function deleteOtherEventsBatch( int $userId, int $limit ): ?int {
		global $wpdb;

		$events = HWE_Memberships_Schema::eventsTable();
		$grants = HWE_Memberships_Schema::grantsTable();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id FROM {$events} e"
				. ' WHERE e.user_id = %d'
				. " AND (e.grant_id IS NULL OR e.grant_id NOT IN (SELECT g.id FROM {$grants} g WHERE g.user_id = %d AND g.origin = '" . self::ORIGIN_ORDER . "'))"
				. ' ORDER BY e.id ASC LIMIT %d',
				$userId,
				$userId,
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			self::log( 'no se pudo leer el historial a borrar del usuario ' . $userId . '.' );
			return null;
		}

		$ids = self::idsOf( $rows );
		if ( array() === $ids ) {
			return 0;
		}

		$deleted = $wpdb->query( "DELETE FROM {$events} WHERE id IN (" . implode( ',', $ids ) . ')' );
		if ( false === $deleted ) {
			self::log( 'no se pudo borrar el historial del usuario ' . $userId . '.' );
			return null;
		}

		return (int) $deleted;
	}

	/** Borra hasta `$limit` concesiones que no son de origen pedido. */
	private static function deleteOtherGrantsBatch( int $userId, int $limit ): ?int {
		global $wpdb;

		$table = HWE_Memberships_Schema::grantsTable();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND origin <> '" . self::ORIGIN_ORDER . "' ORDER BY id ASC LIMIT %d",
				$userId,
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			self::log( 'no se pudieron leer las concesiones a borrar del usuario ' . $userId . '.' );
			return null;
		}

		$ids = self::idsOf( $rows );
		if ( array() === $ids ) {
			return 0;
		}

		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', $ids ) . ') AND user_id = %d',
				$userId
			)
		);
		if ( false === $deleted ) {
			self::log( 'no se pudieron borrar las concesiones del usuario ' . $userId . '.' );
			return null;
		}

		return (int) $deleted;
	}

	/** Borra todos los tokens del carné (también los revocados). `null` si falla. */
	private static function deleteTokens( int $userId ): ?int {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . HWE_Memberships_Schema::cardTokensTable() . ' WHERE user_id = %d', $userId )
		);
		if ( false === $deleted ) {
			self::log( 'no se pudieron borrar los tokens del usuario ' . $userId . '.' );
			return null;
		}

		return (int) $deleted;
	}

	/** Borra la fila del socio. El contador de números no se toca: el número no se reutiliza. */
	private static function deleteMemberRow( int $userId ): ?int {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . HWE_Memberships_Schema::membersTable() . ' WHERE user_id = %d', $userId )
		);
		if ( false === $deleted ) {
			self::log( 'no se pudo borrar la fila del socio ' . $userId . '.' );
			return null;
		}

		return (int) $deleted;
	}

	/**
	 * Bloquea la fila del socio para serializar la limpieza con cualquier concesión concurrente.
	 * Si la fila aún no existe, el bloqueo de hueco de InnoDB sobre la clave primaria impide
	 * que otra transacción la cree mientras dura esta.
	 */
	private static function lockMember( int $userId ): bool {
		global $wpdb;

		$wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Memberships_Schema::membersTable() . ' WHERE user_id = %d FOR UPDATE',
				$userId
			)
		);

		if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) {
			self::log( 'no se pudo bloquear la fila del socio ' . $userId . '.' );
			return false;
		}

		return true;
	}

	/** Elementos que aún tienen datos personales o deben borrarse. `null` si falla la lectura. */
	private static function pendingAnonymizations( int $userId ): ?int {
		$grants = HWE_Memberships_Schema::grantsTable();
		$events = HWE_Memberships_Schema::eventsTable();

		$orderGrants = self::count(
			"SELECT COUNT(*) FROM {$grants} WHERE user_id = %d AND origin = '" . self::ORIGIN_ORDER . "'"
			. " AND (note IS NOT NULL OR data IS NOT NULL OR actor_id = %d OR status IN ('active','scheduled'))",
			array( $userId, $userId ),
			'las concesiones pendientes del usuario ' . $userId
		);
		if ( null === $orderGrants ) {
			return null;
		}

		$orderEvents = self::count(
			"SELECT COUNT(*) FROM {$events} e WHERE e.user_id = %d"
			. " AND e.grant_id IN (SELECT g.id FROM {$grants} g WHERE g.user_id = %d AND g.origin = '" . self::ORIGIN_ORDER . "')"
			. ' AND (e.data IS NOT NULL OR e.actor_id = %d)',
			array( $userId, $userId, $userId ),
			'el historial pendiente del usuario ' . $userId
		);
		if ( null === $orderEvents ) {
			return null;
		}

		$otherEvents = self::count(
			"SELECT COUNT(*) FROM {$events} e WHERE e.user_id = %d"
			. " AND (e.grant_id IS NULL OR e.grant_id NOT IN (SELECT g.id FROM {$grants} g WHERE g.user_id = %d AND g.origin = '" . self::ORIGIN_ORDER . "'))",
			array( $userId, $userId ),
			'el historial a borrar del usuario ' . $userId
		);
		if ( null === $otherEvents ) {
			return null;
		}

		$otherGrants = self::count(
			"SELECT COUNT(*) FROM {$grants} WHERE user_id = %d AND origin <> '" . self::ORIGIN_ORDER . "'",
			array( $userId ),
			'las concesiones a borrar del usuario ' . $userId
		);
		if ( null === $otherGrants ) {
			return null;
		}

		return $orderGrants + $orderEvents + $otherEvents + $otherGrants;
	}

	/** Concesiones de pedido que se conservan (contabilidad). */
	private static function countOrderGrants( int $userId ): int {
		$count = self::count(
			'SELECT COUNT(*) FROM ' . HWE_Memberships_Schema::grantsTable()
			. " WHERE user_id = %d AND origin = '" . self::ORIGIN_ORDER . "'",
			array( $userId ),
			'las concesiones de pedido del usuario ' . $userId
		);

		return null === $count ? 0 : $count;
	}

	// ------------------------------------------------------------------------
	//  Lecturas y utilidades
	// ------------------------------------------------------------------------

	/** Filas del usuario, paginadas. @return list<object> */
	private static function rows( string $table, int $userId, int $offset, int $limit, string $label ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$userId,
				$limit,
				$offset
			)
		);

		if ( ! is_array( $rows ) ) {
			self::log( 'no se pudo leer ' . $label . ' del usuario ' . $userId . '.' );
			return array();
		}

		return array_values(
			array_filter(
				$rows,
				static fn ( mixed $row ): bool => is_object( $row )
			)
		);
	}

	/** Total de filas del usuario en una tabla. Un fallo se registra y se cuenta como 0. */
	private static function countRows( string $table, int $userId, string $label ): int {
		$count = self::count(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
			array( $userId ),
			'el total de ' . $label . ' del usuario ' . $userId
		);

		return null === $count ? 0 : $count;
	}

	/**
	 * Ejecuta un `COUNT(*)` con los argumentos dados. `null` si la lectura falla (nunca se
	 * confunde con 0, que sería dar por terminada una limpieza incompleta).
	 *
	 * @param list<mixed> $args
	 */
	private static function count( string $sql, array $args, string $label ): ?int {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
		if ( null === $value ) {
			self::log( 'no se pudo contar ' . $label . '.' );
			return null;
		}

		return (int) $value;
	}

	/** @param list<object> $rows @return list<int> */
	private static function idsOf( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			$id = (int) ( $row->id ?? 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private static function begin(): bool {
		global $wpdb;

		if ( class_exists( 'HWE_Memberships_Grants' ) ) {
			HWE_Memberships_Grants::flushReadCache();
		}
		return isset( $wpdb ) && is_object( $wpdb ) && false !== $wpdb->query( 'START TRANSACTION' );
	}

	private static function commit(): bool {
		global $wpdb;

		$committed = isset( $wpdb ) && is_object( $wpdb ) && false !== $wpdb->query( 'COMMIT' );
		if ( class_exists( 'HWE_Memberships_Grants' ) ) {
			HWE_Memberships_Grants::flushReadCache();
		}

		return $committed;
	}

	private static function rollback(): void {
		global $wpdb;

		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->query( 'ROLLBACK' );
		}
		if ( class_exists( 'HWE_Memberships_Grants' ) ) {
			HWE_Memberships_Grants::flushReadCache();
		}
	}

	private static function userIdByEmail( string $email ): int {
		if ( '' === trim( $email ) || ! function_exists( 'get_user_by' ) ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );

		return is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
	}

	/**
	 * Nombre del nivel, o «Nivel <id>» si ya no existe (el id no identifica a ninguna persona).
	 */
	private static function levelName( int $levelId ): string {
		if ( $levelId <= 0 ) {
			return '';
		}

		if ( class_exists( 'HWE_Memberships_Levels' ) ) {
			$level = HWE_Memberships_Levels::get( $levelId );
			if ( is_array( $level ) ) {
				$name = trim( (string) ( $level['name'] ?? '' ) );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return 'Nivel ' . $levelId;
	}

	/** @param mixed $queue @return list<string> */
	private static function queueNames( mixed $queue ): array {
		if ( ! is_array( $queue ) ) {
			return array();
		}

		$names = array();
		foreach ( $queue as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$name = self::levelName( (int) ( $entry['level_id'] ?? 0 ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return array_values( array_unique( $names ) );
	}

	private static function stateLabel( string $status ): string {
		switch ( $status ) {
			case HWE_Memberships_Members::STATUS_ACTIVE:
				return 'Activo';
			case HWE_Memberships_Members::STATUS_FROZEN:
				return 'Congelado';
			case HWE_Memberships_Members::STATUS_NONE:
				return 'Sin membresía';
		}

		return $status;
	}

	private static function grantStatusLabel( string $status ): string {
		switch ( $status ) {
			case 'scheduled':
				return 'Programada';
			case 'active':
				return 'Activa';
			case 'ended':
				return 'Terminada';
			case 'revoked':
				return 'Revocada';
		}

		return $status;
	}

	private static function eventTypeLabel( string $type ): string {
		$labels = array(
			HWE_Memberships_Events::ACTIVATED             => 'Activación',
			HWE_Memberships_Events::RENEWED               => 'Renovación',
			HWE_Memberships_Events::UPGRADED              => 'Mejora',
			HWE_Memberships_Events::DEFERRED              => 'Programación',
			HWE_Memberships_Events::EXPIRED               => 'Caducidad',
			HWE_Memberships_Events::EXPIRING              => 'Aviso de caducidad',
			HWE_Memberships_Events::FROZEN                => 'Congelación',
			HWE_Memberships_Events::UNFROZEN              => 'Descongelación',
			HWE_Memberships_Events::GRANTED_BY_ADMIN      => 'Concesión por administrador',
			HWE_Memberships_Events::REMOVED_BY_ADMIN      => 'Retirada por administrador',
			HWE_Memberships_Events::REVOKED               => 'Revocación',
			HWE_Memberships_Events::REFERRAL_GIFT_GRANTED => 'Regalo de referido',
		);

		return $labels[ $type ] ?? $type;
	}

	private static function actorLabel( string $actor ): string {
		switch ( $actor ) {
			case 'system':
				return 'Sistema';
			case 'admin':
				return 'Administrador';
			case 'customer':
				return 'Cliente';
		}

		return $actor;
	}

	/**
	 * @param list<array<string,string>> $data
	 * @return array<string,mixed>
	 */
	private static function item( string $suffix, int $id, array $data ): array {
		return array(
			'group_id'    => self::GROUP_ID,
			'group_label' => self::GROUP_LABEL,
			'item_id'     => self::GROUP_ID . '-' . $suffix . '-' . $id,
			'data'        => $data,
		);
	}

	/**
	 * @return array{name:string,value:string}
	 */
	private static function datum( string $name, string $value ): array {
		return array( 'name' => $name, 'value' => $value );
	}

	/** Ahora en UTC, en el formato de las columnas `datetime` de WordPress. */
	private static function nowSql(): string {
		return gmdate( 'Y-m-d H:i:s', time() );
	}

	/** Fecha de la tabla (`Y-m-d H:i:s`, UTC) en ISO 8601, o `null`. */
	private static function iso( mixed $mysql ): ?string {
		if ( ! is_string( $mysql ) || '' === $mysql ) {
			return null;
		}

		$timestamp = strtotime( $mysql . ' UTC' );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	private static function dateIso( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * @param list<string> $messages
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	private static function eraseResult( bool $removed, bool $retained, array $messages, bool $done ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Mensajes que ve el administrador. Se componen aquí (WordPress no devuelve frases).
	 *
	 * @return list<string>
	 */
	private static function messages( bool $retained, bool $removedChrome ): array {
		$messages = array();

		if ( $retained ) {
			$messages[] = 'Las concesiones y el historial ligados a pedidos se conservan anonimizados por obligación contable.';
		}
		if ( $removedChrome ) {
			$messages[] = 'Se han eliminado el número de socio y los tokens del carné.';
		}

		return $messages;
	}

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: privacidad: ' . $message );
	}
}
