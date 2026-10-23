<?php
/**
 * Privacidad del módulo: exportador y eliminador de datos personales de WordPress, y limpieza
 * cuando se elimina una cuenta (`delete_user`).
 *
 * Reglas (spec §11):
 *  · El **exportador** entrega solo los datos del propio sujeto: saldo, código de referido,
 *    nombre visible del referidor, estado y fechas de la atribución y sus movimientos. Nunca
 *    emails, logins ni ids de terceros; de una transferencia, como mucho el nombre visible de
 *    la otra parte.
 *  · El **eliminador** conserva importes, tipos y fechas del libro porque son contabilidad,
 *    vacía las notas y los datos estructurados de cada movimiento y desvincula la red de
 *    referidos. Mantiene `user_id` (sin él el saldo y la caducidad dejarían de cuadrar) y el
 *    código de referido se sustituye por uno que no pasa la validación, porque la columna es
 *    `NOT NULL UNIQUE`.
 *  · Al **eliminar la cuenta** se borran su fila de referidos y su saldo, sus movimientos se
 *    conservan anonimizados y sus referidos quedan sin referidor. El `reassign` de WordPress se
 *    ignora a propósito: la red no se hereda.
 *
 * Los movimientos se procesan por lotes (nunca se carga el libro entero en memoria) y las dos
 * herramientas son idempotentes. Los logs llevan solo ids.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Privacy {

	/** Id del exportador y del eliminador en las herramientas de privacidad de WordPress. */
	public const EXPORTER_ID = 'hwe-loyalty';
	public const ERASER_ID   = 'hwe-loyalty';

	/** Grupo que ve el administrador en la exportación. */
	public const GROUP_ID    = 'hwe-loyalty';
	public const GROUP_LABEL = 'Fidelización';

	/** Movimientos por página del exportador y por lote del eliminador. */
	public const BATCH = 100;

	/** Tipos de transferencia: son los únicos que pueden mostrar a la otra parte. */
	private const TRANSFER_TYPES = array( 'transfer_out', 'transfer_fee', 'transfer_in' );

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
	 * Exporta los datos de fidelización del usuario dueño de `$email`, paginando los movimientos
	 * de `BATCH` en `BATCH`. La primera página incluye además el resumen del monedero y la red.
	 *
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public static function exportPersonalData( string $email, int $page = 1 ): array {
		$userId = self::userIdByEmail( $email );
		if ( $userId <= 0 ) {
			return array( 'data' => array(), 'done' => true );
		}

		$page  = max( 1, $page );
		$items = array();

		if ( 1 === $page ) {
			$items[] = self::summaryItem( $userId );
		}

		$history = HWE_Loyalty_Ledger::history( $userId, $page, self::BATCH );
		foreach ( $history['items'] as $entry ) {
			$items[] = self::entryItem( $entry );
		}

		return array(
			'data' => $items,
			'done' => $page * self::BATCH >= (int) $history['total'],
		);
	}

	/** @return array<string,mixed> */
	private static function summaryItem( int $userId ): array {
		$balance = HWE_Loyalty_Ledger::balance( $userId );
		$data    = array(
			self::datum( 'Saldo', (string) $balance['balance'] ),
			self::datum( 'Total ganado', (string) $balance['earned'] ),
			self::datum( 'Total usado', (string) $balance['used'] ),
		);

		$code = HWE_Loyalty_Referrals::storedCode( $userId );
		if ( '' !== $code ) {
			$data[] = self::datum( 'Código de referido', $code );
		}

		$attribution = HWE_Loyalty_Referrals::attributionOf( $userId );
		if ( null !== $attribution ) {
			// Solo el nombre visible del referidor: nunca su id, email o login.
			$referrer = self::displayName( HWE_Loyalty_Referrals::referrerOf( $userId ) );
			if ( '' !== $referrer ) {
				$data[] = self::datum( 'Referidor', $referrer );
			}

			$data[] = self::datum( 'Estado de la atribución', self::statusLabel( $attribution['status'] ) );

			$attributed = self::iso( $attribution['attributed_at'] );
			if ( null !== $attributed ) {
				$data[] = self::datum( 'Fecha de atribución', $attributed );
			}

			$confirmed = self::iso( $attribution['confirmed_at'] );
			if ( null !== $confirmed ) {
				$data[] = self::datum( 'Fecha de confirmación', $confirmed );
			}
		}

		return array(
			'group_id'    => self::GROUP_ID,
			'group_label' => self::GROUP_LABEL,
			'item_id'     => self::GROUP_ID . '-summary-' . $userId,
			'data'        => $data,
		);
	}

	/**
	 * Un movimiento del libro. El `data` crudo puede llevar ids de la otra parte de una
	 * transferencia; aquí solo se leen las claves conocidas y el nombre visible de esa parte.
	 *
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	private static function entryItem( array $entry ): array {
		$data = array(
			self::datum( 'Fecha (UTC)', (string) ( $entry['created_at'] ?? '' ) ),
			self::datum( 'Tipo', (string) ( $entry['type'] ?? '' ) ),
			self::datum( 'Cantidad', (string) (int) ( $entry['points'] ?? 0 ) ),
		);

		$expires = self::iso( $entry['expires_at'] ?? null );
		if ( null !== $expires ) {
			$data[] = self::datum( 'Caducidad (UTC)', $expires );
		}

		$raw  = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();
		$type = (string) ( $entry['type'] ?? '' );

		if ( isset( $raw['order'] ) && is_scalar( $raw['order'] ) ) {
			$data[] = self::datum( 'Pedido', (string) $raw['order'] );
		}

		if ( in_array( $type, self::TRANSFER_TYPES, true ) ) {
			$counterpart = 'transfer_in' === $type ? ( $raw['from'] ?? null ) : ( $raw['to'] ?? null );
			$name        = is_numeric( $counterpart ) ? self::displayName( (int) $counterpart ) : '';
			if ( '' !== $name ) {
				$data[] = self::datum( 'Otra parte', $name );
			}
		}

		$note = isset( $entry['note'] ) && is_scalar( $entry['note'] ) ? trim( (string) $entry['note'] ) : '';
		if ( '' !== $note ) {
			$data[] = self::datum( 'Nota', $note );
		}

		return array(
			'group_id'    => self::GROUP_ID,
			'group_label' => self::GROUP_LABEL,
			'item_id'     => self::GROUP_ID . '-entry-' . (int) ( $entry['id'] ?? 0 ),
			'data'        => $data,
		);
	}

	// ------------------------------------------------------------------------
	//  Eliminador (la cuenta puede seguir existiendo)
	// ------------------------------------------------------------------------

	/**
	 * Anonimiza los datos personales del usuario dueño de `$email` sin borrar la contabilidad.
	 * Paginado por lotes: cada llamada limpia hasta `BATCH` movimientos pendientes y devuelve
	 * `done: false` mientras queden; WordPress vuelve a llamar con la página siguiente.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public static function erasePersonalData( string $email, int $page = 1 ): array {
		$userId = self::userIdByEmail( $email );
		if ( $userId <= 0 ) {
			return self::eraseResult( false, false, array(), true );
		}

		// `$page` no cambia el lote: siempre se toman las filas que aún tienen datos, así una
		// pasada repetida no salta movimientos por un offset desplazado.
		$removed  = false;
		$unlinked = false;

		if ( HWE_Loyalty_Referrals::anonymize( $userId ) ) {
			$removed  = true;
			$unlinked = true;
		}
		if ( HWE_Loyalty_Referrals::unlinkNetwork( $userId ) > 0 ) {
			$removed  = true;
			$unlinked = true;
		}
		if ( self::anonymizeLedgerBatch( $userId, self::BATCH ) > 0 ) {
			$removed = true;
		}

		$pending  = self::pendingAnonymizations( $userId );
		$retained = self::ledgerRows( $userId ) > 0;
		$messages = array();

		if ( $retained ) {
			$messages[] = 'Los importes, los tipos y las fechas de los movimientos se conservan anonimizados por obligación contable.';
		}
		if ( $unlinked ) {
			$messages[] = 'La red de referidos se ha desvinculado y el código de referido ya no sirve para atribuir a nadie.';
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
	 * propósito, porque la red y los puntos no se heredan.
	 *
	 * @param int         $userId   Cuenta que WordPress va a eliminar.
	 * @param mixed       $reassign Usuario de reasignación de contenidos (se ignora).
	 * @param object|null $user     Objeto de la cuenta (no se usa).
	 */
	public static function onDeleteUser( int $userId, mixed $reassign = null, mixed $user = null ): void {
		self::deleteUserData( $userId );
	}

	/**
	 * Anonimiza el libro por lotes, desvincula la red, borra la fila de referidos y retira el
	 * saldo del circuito. Idempotente: repetirlo no cambia nada.
	 */
	public static function deleteUserData( int $userId ): void {
		if ( $userId <= 0 ) {
			return;
		}

		$anonymized = 0;
		while ( true ) {
			$changed     = self::anonymizeLedgerBatch( $userId, self::BATCH );
			$anonymized += $changed;
			if ( $changed <= 0 ) {
				break;
			}
		}

		$unlinked = HWE_Loyalty_Referrals::unlinkNetwork( $userId );
		$forgotten = HWE_Loyalty_Referrals::forget( $userId );
		$balance   = self::deleteBalanceRow( $userId );
		$pending   = self::pendingAnonymizations( $userId );

		self::log(
			'cuenta eliminada: usuario ' . $userId
			. ', movimientos anonimizados ' . $anonymized
			. ', referidos desvinculados ' . $unlinked
			. ', fila de referidos ' . ( $forgotten ? 'borrada' : 'falló' )
			. ', saldo ' . ( $balance ? 'retirado' : 'falló' )
			. ', pendientes ' . $pending . '.'
		);

		if ( $pending > 0 ) {
			// El hook se ejecuta una sola vez; si quedara algo, la próxima pasada del eliminador
			// de privacidad (o una nueva llamada) lo termina. Se registra con ids, sin PII.
			self::log( 'quedaron ' . $pending . ' movimientos sin anonimizar del usuario ' . $userId . '.' );
		}
	}

	// ------------------------------------------------------------------------
	//  Lotes y escrituras
	// ------------------------------------------------------------------------

	/** Anonimiza hasta `$limit` movimientos del usuario. Devuelve cuántos actualizó. */
	private static function anonymizeLedgerBatch( int $userId, int $limit ): int {
		if ( $userId <= 0 || $limit <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = HWE_Loyalty_Schema::ledgerTable();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, data FROM {$table}"
				. ' WHERE user_id = %d AND (note IS NOT NULL OR data IS NOT NULL) ORDER BY id ASC LIMIT %d',
				$userId,
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			self::log( 'no se pudieron leer los movimientos a anonimizar del usuario ' . $userId . '.' );
			return 0;
		}

		$changed = 0;
		foreach ( $rows as $row ) {
			$id = (int) ( $row->id ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}

			// Se vacían nota y `data` enteros: cualquier clave podría identificar a alguien
			// (ids de la otra parte de una transferencia, nombres, notas) y el detalle
			// contable que hace falta —puntos, tipo, fecha, referencia— vive en columnas
			// propias que no se tocan.
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET note = NULL, data = NULL WHERE id = %d AND user_id = %d",
					$id,
					$userId
				)
			);
			if ( false === $updated ) {
				self::log( 'no se pudo anonimizar el movimiento ' . $id . ' del usuario ' . $userId . '.' );
				continue;
			}
			$changed += (int) $updated;
		}

		return $changed;
	}

	/** Filas del libro que todavía tienen nota o datos por limpiar. */
	private static function pendingAnonymizations( int $userId ): int {
		if ( $userId <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = HWE_Loyalty_Schema::ledgerTable();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND (note IS NOT NULL OR data IS NOT NULL)",
				$userId
			)
		);
	}

	/** Filas del libro del usuario, anonimizadas o no (lo que se conserva por contabilidad). */
	private static function ledgerRows( int $userId ): int {
		if ( $userId <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = HWE_Loyalty_Schema::ledgerTable();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $userId )
		);
	}

	/** Retira la fila de saldo de una cuenta eliminada. */
	private static function deleteBalanceRow( int $userId ): bool {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . HWE_Loyalty_Schema::balancesTable() . ' WHERE user_id = %d', $userId )
		);

		if ( false === $deleted ) {
			self::log( 'no se pudo borrar el saldo del usuario ' . $userId . '.' );
			return false;
		}

		return true;
	}

	// ------------------------------------------------------------------------
	//  Utilidades
	// ------------------------------------------------------------------------

	private static function userIdByEmail( string $email ): int {
		if ( '' === trim( $email ) || ! function_exists( 'get_user_by' ) ) {
			return 0;
		}

		$user = get_user_by( 'email', $email );

		return is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
	}

	/** Nombre visible; nunca login ni email. Vacío si la cuenta ya no existe. */
	private static function displayName( ?int $userId ): string {
		if ( null === $userId || $userId <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user = get_userdata( $userId );
		if ( ! is_object( $user ) || ! isset( $user->display_name ) || ! is_scalar( $user->display_name ) ) {
			return '';
		}

		return trim( (string) $user->display_name );
	}

	private static function statusLabel( string $status ): string {
		if ( HWE_Loyalty_Referrals::STATUS_CONFIRMED === $status ) {
			return 'Confirmada';
		}
		if ( HWE_Loyalty_Referrals::STATUS_PENDING === $status ) {
			return 'Pendiente';
		}

		return $status;
	}

	/**
	 * @return array{name:string,value:string}
	 */
	private static function datum( string $name, string $value ): array {
		return array( 'name' => $name, 'value' => $value );
	}

	/** Fecha de la tabla (`Y-m-d H:i:s`, UTC) en ISO 8601, o `null`. */
	private static function iso( mixed $mysql ): ?string {
		if ( ! is_string( $mysql ) || '' === $mysql ) {
			return null;
		}

		$timestamp = strtotime( $mysql . ' UTC' );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
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

	private static function log( string $message ): void {
		error_log( '[hwe] loyalty: privacidad: ' . $message );
	}
}
