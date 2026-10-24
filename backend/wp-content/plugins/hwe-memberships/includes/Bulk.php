<?php
/**
 * Operaciones en masa de `hwe-memberships` (spec §11.3).
 *
 * Una operación en masa se compone de una **selección** y una **operación**:
 *  · selección: todos los participantes (los roles de `Settings::participantRoles()`), los que
 *    no tienen membresía, los de un nivel, o una lista pegada de emails o ids;
 *  · operación: asignar un nivel (por días o indefinido, en modo `only_new`, `extend_same` o
 *    `replace`), quitar, congelar o descongelar.
 *
 * La **vista previa** (`preview()`) recorre la selección sin escribir nada y devuelve el número
 * de afectados por caso más los tokens de la lista que no se encontraron. La **ejecución**
 * (`start()`) guarda la selección y el criterio en `hwe_memberships_batches` y encola la primera
 * página en Action Scheduler (grupo `hwe-memberships`).
 *
 * Reglas que no cambia ninguna llamada:
 *  · **Sin listas de ids pendientes**: la selección es un criterio y un cursor (`last_user_id`,
 *    el último `user_id` procesado). Cada pasada lee la página siguiente ordenada por id, nunca
 *    una fila de `wp_options` con usuarios.
 *  · **Idempotencia por lote + usuario**: las concesiones usan la clave
 *    `bulk:<lote>:<usuario>`; el índice ÚNICO de la tabla es la garantía. Quitar, congelar y
 *    descongelar vuelven a comprobar el estado antes de actuar, así que reanudar no repite la
 *    acción. En `replace` se comprueba primero la clave de la concesión para no retirar el nivel
 *    que el propio lote acaba de conceder.
 *  · **Historial con lote y admin**: toda escritura delega en `HWE_Memberships_Grants` (que ya
 *    abre la transacción, bloquea la fila del socio y escribe el evento) y le pasa el id del lote
 *    en `data`; en `remove` el motivo sigue siendo «bulk» y el lote va en los datos.
 *  · **Progreso honesto**: `total` es el tamaño de la selección, `processed` los usuarios
 *    revisados y `applied` las acciones que cambiaron algo. Un fallo de un usuario no detiene el
 *    lote; un fallo de la consulta de la página lo marca `failed` y `resume()` lo reencola.
 *  · **Sin Action Scheduler no se arranca**: `function_exists()` es la puerta; `start()` devuelve
 *    un `WP_Error` y deja el motivo en el log.
 *
 * Los textos que ve el cliente se componen en el BFF: aquí solo viajan ids y datos estructurados.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Bulk {

	/** Hook de Action Scheduler que procesa una página de un lote. */
	public const HOOK = 'hwe_memberships_bulk_process';

	/** Grupo propio en Action Scheduler (no se mezcla con otras tareas de WooCommerce). */
	public const GROUP = 'hwe-memberships';

	/** Usuarios por página de ejecución. */
	public const PAGE_SIZE = 100;

	/** Tope de entradas de una lista pegada (emails o ids). */
	public const LIST_MAX = 5000;

	/** Tope de usuarios que recorre la vista previa (el lote no tiene tope). */
	public const PREVIEW_MAX = 5000;

	/** Usuarios por consulta durante la vista previa. */
	private const PREVIEW_PAGE = 500;

	/** Días máximos de una concesión en masa (100 años). */
	private const MAX_DAYS = 36500;

	public const STATUS_PENDING = 'pending';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	public const SELECTION_PARTICIPANTS  = 'participants';
	public const SELECTION_NO_MEMBERSHIP = 'no_membership';
	public const SELECTION_LEVEL         = 'level';
	public const SELECTION_LIST          = 'list';

	public const OPERATION_GRANT    = 'grant';
	public const OPERATION_REMOVE   = 'remove';
	public const OPERATION_FREEZE   = 'freeze';
	public const OPERATION_UNFREEZE = 'unfreeze';

	public const MODE_ONLY_NEW    = 'only_new';
	public const MODE_EXTEND_SAME = 'extend_same';
	public const MODE_REPLACE     = 'replace';

	/** Prefijo de la clave idempotente de una concesión en masa. */
	public const KEY_PREFIX = 'bulk:';

	/** Engancha el procesador de páginas. Lo llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/** Callback de Action Scheduler: procesa la página pendiente y programa la siguiente. */
	public static function run( $batchId ): void {
		$id = is_scalar( $batchId ) ? (string) $batchId : '';
		if ( '' === $id ) {
			self::log( 'se recibió una acción de lote sin id.' );
			return;
		}

		self::process( $id );
	}

	// ------------------------------------------------------------------------
	//  Vista previa
	// ------------------------------------------------------------------------

	/**
	 * Recorre la selección sin escribir y devuelve los afectados por caso y lo que no encontró.
	 *
	 * `total` son los usuarios revisados (como mucho `PREVIEW_MAX`; `truncated` avisa si la
	 * selección sigue), `affected` los que cambiarían y `skipped` el resto. `counts` trae una
	 * clave por caso (`grant`, `replace`, `remove`, `freeze`, `unfreeze` y `skip_*`) para que la
	 * pantalla pueda pintarlos sin interpretar la operación.
	 *
	 * @param array<string,mixed> $selection
	 * @param array<string,mixed> $operation
	 * @return array<string,mixed>
	 */
	public static function preview( array $selection, array $operation ): array {
		$request = self::normalizeRequest( $selection, $operation );
		if ( isset( $request['error'] ) ) {
			return self::previewError( $request );
		}

		$selection = $request['selection'];
		$operation = $request['operation'];

		if ( self::SELECTION_LIST !== $selection['type'] && array() === self::selectionRoles( $selection ) ) {
			return self::previewError( self::error( 'no_participants' ) );
		}

		$counts    = self::emptyCounts( $operation );
		$now       = self::now();
		$total     = 0;
		$truncated = false;
		$cursor    = 0;

		try {
			while ( true ) {
				$page = self::candidates( $selection, $cursor, self::PREVIEW_PAGE, $now );
				if ( array() === $page ) {
					break;
				}

				$snapshots = self::snapshots( $page, $now );

				foreach ( $page as $userId ) {
					if ( $total >= self::PREVIEW_MAX ) {
						$truncated = true;
						break 2;
					}

					++$total;
					$case = self::decide( $snapshots[ (int) $userId ] ?? self::emptySnapshot(), $operation );
					$counts[ $case ] = (int) ( $counts[ $case ] ?? 0 ) + 1;
				}

				$cursor = (int) end( $page );
			}
		} catch ( \Throwable $e ) {
			self::log( 'la vista previa no pudo leer la selección: ' . $e->getMessage() );
			return self::previewError( self::error( 'query_failed' ) );
		}

		$affected = 0;
		$skipped  = 0;
		foreach ( $counts as $case => $count ) {
			if ( self::isSkipCase( (string) $case ) ) {
				$skipped += (int) $count;
			} else {
				$affected += (int) $count;
			}
		}

		$warnings = array();
		if ( $truncated ) {
			$warnings[] = 'preview_truncated';
		}

		$missing = isset( $selection['missing'] ) && is_array( $selection['missing'] )
			? array_values( $selection['missing'] )
			: array();

		return array(
			'selection'     => $selection,
			'operation'     => $operation,
			'total'         => $total,
			'affected'      => $affected,
			'skipped'       => $skipped,
			'counts'        => $counts,
			'missing'       => $missing,
			'missing_count' => count( $missing ),
			'truncated'     => $truncated,
			'warnings'      => $warnings,
		);
	}

	// ------------------------------------------------------------------------
	//  Arranque y progreso
	// ------------------------------------------------------------------------

	/**
	 * Crea el lote y encola su primera página. Devuelve el id o un `WP_Error`.
	 *
	 * La selección se guarda ya resuelta (la lista pegada pasa a ids, con su tope); el progreso
	 * empieza en el cursor 0 y no se materializa ninguna otra lista.
	 *
	 * @param array<string,mixed> $selection
	 * @param array<string,mixed> $operation
	 * @return int|\WP_Error
	 */
	public static function start( array $selection, array $operation, int $actorId = 0 ) {
		$request = self::normalizeRequest( $selection, $operation );
		if ( isset( $request['error'] ) ) {
			return self::wpError( $request );
		}

		$selection = $request['selection'];
		$operation = $request['operation'];

		if ( ! self::schedulerAvailable() ) {
			self::log( 'no se pudo iniciar el lote: Action Scheduler no está disponible.' );
			return self::wpError( self::error( 'as_unavailable' ) );
		}

		if ( self::SELECTION_LIST !== $selection['type'] ) {
			$roles = self::selectionRoles( $selection );
			if ( array() === $roles ) {
				self::log( 'no se pudo iniciar el lote: no hay roles participantes configurados.' );
				return self::wpError( self::error( 'no_participants' ) );
			}
			$selection['roles'] = $roles;
		}

		// Los tokens que no encontraron usuario se muestran en la vista previa; en el lote solo
		// queda su número: no hay motivo para guardar emails fallidos.
		$missingCount = (int) ( $selection['missing_count'] ?? 0 );
		unset( $selection['missing'] );

		$total = self::countCandidates( $selection );
		if ( $total < 0 ) {
			return self::wpError( self::error( 'query_failed' ) );
		}
		if ( 0 === $total ) {
			return self::wpError( self::error( 'empty_selection' ) );
		}

		$nowSql  = self::sqlDate( self::now() );
		$batchId = self::insertBatch(
			array(
				'operation'  => (string) $operation['type'],
				'selection'  => self::json( $selection ),
				'params'     => self::json(
					array(
						'operation'     => $operation,
						'missing_count' => $missingCount,
					)
				),
				'total'        => $total,
				'processed'    => 0,
				'applied'      => 0,
				'last_user_id' => 0,
				'status'       => self::STATUS_PENDING,
				'actor_id'     => $actorId > 0 ? $actorId : null,
				'created_at'   => $nowSql,
				'updated_at'   => $nowSql,
			)
		);

		if ( $batchId <= 0 ) {
			self::log( 'no se pudo guardar el lote en la base de datos.' );
			return self::wpError( self::error( 'store_failed' ) );
		}

		if ( ! self::enqueue( (string) $batchId ) ) {
			self::updateBatch(
				$batchId,
				array(
					'status'      => self::STATUS_FAILED,
					'updated_at'  => $nowSql,
					'finished_at' => $nowSql,
				)
			);
			self::log( 'no se pudo encolar el lote ' . $batchId . ' en Action Scheduler.' );
			return self::wpError( self::error( 'enqueue_failed' ) );
		}

		return $batchId;
	}

	/**
	 * Estado de un lote. Devuelve `['error'=>'not_found']` si el id no existe.
	 *
	 * @return array<string,mixed>
	 */
	public static function progress( string $batchId ): array {
		$row = self::find( $batchId );
		if ( null === $row ) {
			return array( 'error' => 'not_found' );
		}

		return self::normalizeRow( $row );
	}

	/**
	 * Reencola un lote que quedó pendiente o falló. No toca un lote terminado.
	 */
	public static function resume( string $batchId ): bool {
		$row = self::find( $batchId );
		if ( null === $row || self::STATUS_DONE === (string) $row['status'] ) {
			return false;
		}

		if ( ! self::schedulerAvailable() ) {
			self::log( 'no se pudo reanudar el lote ' . (int) $row['id'] . ': Action Scheduler no está disponible.' );
			return false;
		}

		$nowSql = self::sqlDate( self::now() );
		if ( ! self::updateBatch(
			(int) $row['id'],
			array(
				'status'      => self::STATUS_PENDING,
				'finished_at' => null,
				'updated_at'  => $nowSql,
			)
		) ) {
			self::log( 'no se pudo marcar como pendiente el lote ' . (int) $row['id'] . '.' );
			return false;
		}

		if ( ! self::enqueue( (string) (int) $row['id'] ) ) {
			self::updateBatch(
				(int) $row['id'],
				array(
					'status'      => self::STATUS_FAILED,
					'updated_at'  => $nowSql,
					'finished_at' => $nowSql,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Últimos lotes, del más nuevo al más antiguo, para la pantalla de progreso.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Memberships_Schema::batchesTable() . ' ORDER BY id DESC LIMIT %d',
				$limit
			)
		);

		if (
			! is_array( $rows )
			|| ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error )
		) {
			self::log( 'no se pudieron leer los lotes recientes.' );
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_object( $row ) ) {
				$out[] = self::normalizeRow( (array) $row );
			}
		}

		return $out;
	}

	// ------------------------------------------------------------------------
	//  Procesamiento por páginas
	// ------------------------------------------------------------------------

	/**
	 * Procesa la página pendiente del lote y programa la siguiente si queda trabajo.
	 *
	 * Es reanudable: el cursor solo avanza cuando la página se escribió; si la actualización
	 * falla, la siguiente pasada repite la misma página y las claves de idempotencia (o la
	 * comprobación de estado) impiden duplicar nada.
	 *
	 * @return array<string,mixed> Progreso del lote más el resumen de esta pasada en `run`.
	 */
	public static function process( string $batchId, ?int $pageSize = null ): array {
		$row = self::find( $batchId );
		if ( null === $row ) {
			return array( 'error' => 'not_found' );
		}

		$status = (string) $row['status'];
		if ( in_array( $status, array( self::STATUS_DONE, self::STATUS_FAILED ), true ) ) {
			return self::progress( $batchId );
		}

		$selection = self::decode( $row['selection'] ?? null );
		$params    = self::decode( $row['params'] ?? null );
		$operation = isset( $params['operation'] ) && is_array( $params['operation'] )
			? $params['operation']
			: array();

		if ( array() === $selection || array() === $operation ) {
			self::log( 'el lote ' . (int) $row['id'] . ' no tiene selección u operación válidas.' );
			self::failBatch( (int) $row['id'] );
			return self::progress( $batchId );
		}

		$pageSize = max( 1, min( 500, null === $pageSize ? self::PAGE_SIZE : $pageSize ) );
		$now      = self::now();
		$nowSql   = self::sqlDate( $now );
		$batchInt = (int) $row['id'];
		$actorId  = (int) ( $row['actor_id'] ?? 0 );

		try {
			$page = self::candidates( $selection, (int) ( $row['last_user_id'] ?? 0 ), $pageSize, $now );
		} catch ( \Throwable $e ) {
			self::log( 'el lote ' . $batchInt . ' no pudo leer su página: ' . $e->getMessage() );
			self::failBatch( $batchInt );
			return self::progress( $batchId );
		}

		if ( array() === $page ) {
			self::updateBatch(
				$batchInt,
				array(
					'status'      => self::STATUS_DONE,
					'updated_at'  => $nowSql,
					'finished_at' => $nowSql,
				)
			);
			return self::progress( $batchId );
		}

		// Visible como «en curso» mientras se procesa; un fallo a mitad deja el lote reanudable.
		self::updateBatch( $batchInt, array( 'status' => self::STATUS_RUNNING, 'updated_at' => $nowSql ) );

		try {
			$snapshots = self::snapshots( $page, $now );
		} catch ( \Throwable $e ) {
			self::log( 'el lote ' . $batchInt . ' no pudo leer el estado de sus socios: ' . $e->getMessage() );
			self::failBatch( $batchInt );
			return self::progress( $batchId );
		}

		$applied = 0;
		$skipped = 0;
		$failed  = 0;
		$cases   = array();

		foreach ( $page as $userId ) {
			try {
				$case = self::applyOne(
					(int) $userId,
					$snapshots[ (int) $userId ] ?? self::emptySnapshot(),
					$operation,
					$batchInt,
					$actorId
				);
			} catch ( \Throwable $e ) {
				self::log( 'el lote ' . $batchInt . ' falló para el usuario ' . (int) $userId . '.' );
				$case = 'failed';
			}

			$cases[ $case ] = (int) ( $cases[ $case ] ?? 0 ) + 1;

			if ( self::isApplyCase( $case ) ) {
				++$applied;
			} elseif ( 'failed' === $case ) {
				++$failed;
				self::log( 'el lote ' . $batchInt . ' no pudo aplicar la operación al usuario ' . (int) $userId . '.' );
			} else {
				++$skipped;
			}
		}

		$more = count( $page ) >= $pageSize;
		if ( $more ) {
			// Una página llena no significa que quede trabajo: se mira la siguiente antes de
			// programar otra pasada, para que el lote termine con el estado correcto.
			try {
				$more = array() !== self::candidates( $selection, (int) end( $page ), 1, $now );
			} catch ( \Throwable $e ) {
				$more = true;
			}
		}

		$data = array(
			'last_user_id' => (int) end( $page ),
			'processed'    => (int) $row['processed'] + count( $page ),
			'applied'      => (int) $row['applied'] + $applied,
			'status'       => $more ? self::STATUS_RUNNING : self::STATUS_DONE,
			'updated_at'   => $nowSql,
			'finished_at'  => $more ? null : $nowSql,
		);

		if ( ! self::updateBatch( $batchInt, $data ) ) {
			// El cursor no avanza: la página se repetirá y la idempotencia la hace inocua.
			self::log( 'no se pudo guardar el progreso del lote ' . $batchInt . '.' );
		}

		if ( $more ) {
			self::scheduleContinuation( $batchInt );
		}

		$progress        = self::progress( $batchId );
		$progress['run'] = array(
			'applied' => $applied,
			'skipped' => $skipped,
			'failed'  => $failed,
			'cases'   => $cases,
		);

		return $progress;
	}

	/** ¿Action Scheduler está cargado? Sin él el lote no puede ni arrancar ni continuar. */
	public static function schedulerAvailable(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' );
	}

	// ------------------------------------------------------------------------
	//  Decisión por usuario
	// ------------------------------------------------------------------------

	/**
	 * Aplica la operación a un usuario y devuelve el caso resultante. No abre transacción por su
	 * cuenta: delega en `HWE_Memberships_Grants`, que bloquea la fila y escribe el historial.
	 *
	 * @param array{live:bool,levels:array<int,true>,frozen:bool} $snapshot
	 */
	private static function applyOne(
		int $userId,
		array $snapshot,
		array $operation,
		int $batchId,
		int $actorId
	): string {
		$case = self::decide( $snapshot, $operation );

		if ( ! self::isApplyCase( $case ) ) {
			return $case;
		}

		$type = (string) $operation['type'];

		if ( self::OPERATION_GRANT === $type ) {
			$key  = self::grantKey( $batchId, $userId );
			$mode = (string) $operation['mode'];

			if ( self::MODE_REPLACE === $mode && self::grantExists( $key ) ) {
				// Reintento de una página ya aplicada: no se retira el nivel que este lote dio.
				return 'skip_already_applied';
			}

			if ( self::MODE_REPLACE === $mode ) {
				HWE_Memberships_Grants::removeAll(
					$userId,
					$actorId,
					'bulk_replace',
					array(
						'batch'     => $batchId,
						'operation' => self::MODE_REPLACE,
					)
				);
			}

			$grantId = HWE_Memberships_Grants::grant(
				$userId,
				(int) $operation['level_id'],
				(int) $operation['days'],
				HWE_Memberships_Grants::ORIGIN_BULK,
				$key,
				array(
					'type' => 'bulk',
					'id'   => $batchId,
				),
				$actorId,
				array(
					'batch'     => $batchId,
					'operation' => self::OPERATION_GRANT,
					'mode'      => $mode,
				)
			);

			if ( null !== $grantId ) {
				return $case;
			}

			return self::grantExists( $key ) ? 'skip_already_applied' : 'failed';
		}

		if ( self::OPERATION_REMOVE === $type ) {
			$ok = HWE_Memberships_Grants::removeAll(
				$userId,
				$actorId,
				'bulk',
				array(
					'batch'     => $batchId,
					'operation' => self::OPERATION_REMOVE,
				)
			);

			return $ok ? 'remove' : 'failed';
		}

		if ( self::OPERATION_FREEZE === $type ) {
			$ok = HWE_Memberships_Grants::freeze(
				$userId,
				$actorId,
				array(
					'batch'     => $batchId,
					'operation' => self::OPERATION_FREEZE,
				)
			);

			return $ok ? 'freeze' : 'failed';
		}

		if ( self::OPERATION_UNFREEZE === $type ) {
			$ok = HWE_Memberships_Grants::unfreeze(
				$userId,
				$actorId,
				array(
					'batch'     => $batchId,
					'operation' => self::OPERATION_UNFREEZE,
				)
			);

			return $ok ? 'unfreeze' : 'failed';
		}

		return 'skip_no_membership';
	}

	/**
	 * Caso de un usuario dentro de la operación. `live` = tiene alguna concesión viva por fechas
	 * (activa o en cola); `levels` = niveles de esas concesiones; `frozen` = congelado.
	 *
	 * @param array{live:bool,levels:array<int,true>,frozen:bool} $snapshot
	 */
	private static function decide( array $snapshot, array $operation ): string {
		$live   = (bool) $snapshot['live'];
		$levels = $snapshot['levels'];
		$frozen = (bool) $snapshot['frozen'];
		$type   = (string) $operation['type'];

		if ( self::OPERATION_GRANT === $type ) {
			$levelId = (int) $operation['level_id'];

			if ( self::MODE_EXTEND_SAME === (string) $operation['mode'] ) {
				if ( $frozen ) {
					return 'skip_frozen';
				}
				if ( isset( $levels[ $levelId ] ) ) {
					return 'grant';
				}
				return $live ? 'skip_other_level' : 'skip_no_membership';
			}

			if ( self::MODE_REPLACE === (string) $operation['mode'] ) {
				return $live ? self::MODE_REPLACE : 'grant';
			}

			return $live ? 'skip_has_membership' : 'grant';
		}

		if ( self::OPERATION_REMOVE === $type ) {
			return $live ? 'remove' : 'skip_no_membership';
		}

		if ( self::OPERATION_FREEZE === $type ) {
			if ( $frozen ) {
				return 'skip_frozen';
			}
			return $live ? 'freeze' : 'skip_no_membership';
		}

		if ( self::OPERATION_UNFREEZE === $type ) {
			return $frozen ? 'unfreeze' : 'skip_not_frozen';
		}

		return 'skip_no_membership';
	}

	/**
	 * Estado de una página de usuarios en dos consultas (no dos por usuario): las congelaciones
	 * de `members` y las concesiones vivas de `grants`. `live` se decide por fechas para no
	 * depender de lo que la tarea diaria ya haya materializado.
	 *
	 * @param list<int> $userIds
	 * @return array<int,array{live:bool,levels:array<int,true>,frozen:bool}>
	 * @throws \RuntimeException Si alguna de las dos lecturas falla.
	 */
	private static function snapshots( array $userIds, DateTimeImmutable $now ): array {
		$out = array();
		$ids = array();

		foreach ( $userIds as $userId ) {
			$userId = (int) $userId;
			if ( $userId > 0 ) {
				$ids[ $userId ] = $userId;
				$out[ $userId ] = self::emptySnapshot();
			}
		}

		if ( array() === $ids ) {
			return $out;
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $out;
		}

		$ids          = array_values( $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$members = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, frozen_at FROM ' . HWE_Memberships_Schema::membersTable()
					. " WHERE user_id IN ({$placeholders})",
				...$ids
			)
		);
		if (
			! is_array( $members )
			|| ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error )
		) {
			self::log( 'no se pudieron leer las congelaciones de la página del lote.' );
			throw new \RuntimeException( 'batch_members_failed' );
		}

		foreach ( $members as $row ) {
			$userId = (int) ( is_object( $row ) ? ( $row->user_id ?? 0 ) : ( $row['user_id'] ?? 0 ) );
			$frozen = is_object( $row ) ? ( $row->frozen_at ?? null ) : ( $row['frozen_at'] ?? null );
			if ( $userId > 0 && null !== $frozen && '' !== (string) $frozen && ! str_starts_with( (string) $frozen, '0000-00-00' ) ) {
				$out[ $userId ]['frozen'] = true;
			}
		}

		$grants = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, level_id, ends_at FROM ' . HWE_Memberships_Schema::grantsTable()
					. " WHERE user_id IN ({$placeholders}) AND status IN ('active','scheduled')",
				...$ids
			)
		);
		if (
			! is_array( $grants )
			|| ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error )
		) {
			self::log( 'no se pudieron leer las concesiones de la página del lote.' );
			throw new \RuntimeException( 'batch_grants_failed' );
		}

		$nowSql = self::sqlDate( $now );

		foreach ( $grants as $row ) {
			$userId = (int) ( is_object( $row ) ? ( $row->user_id ?? 0 ) : ( $row['user_id'] ?? 0 ) );
			if ( $userId <= 0 || ! isset( $out[ $userId ] ) ) {
				continue;
			}

			$end = is_object( $row ) ? ( $row->ends_at ?? null ) : ( $row['ends_at'] ?? null );
			if ( null !== $end && '' !== (string) $end && (string) $end <= $nowSql ) {
				continue;
			}

			$out[ $userId ]['live'] = true;
			$levelId                = (int) ( is_object( $row ) ? ( $row->level_id ?? 0 ) : ( $row['level_id'] ?? 0 ) );
			if ( $levelId > 0 ) {
				$out[ $userId ]['levels'][ $levelId ] = true;
			}
		}

		return $out;
	}

	/** @return array{live:bool,levels:array<int,true>,frozen:bool} */
	private static function emptySnapshot(): array {
		return array(
			'live'   => false,
			'levels' => array(),
			'frozen' => false,
		);
	}

	// ------------------------------------------------------------------------
	//  Selecciones y candidatos
	// ------------------------------------------------------------------------

	/**
	 * Página de usuarios de la selección, ordenada por id y estrictamente posterior al cursor.
	 * Una lista pegada se resuelve a ids al normalizar; no vuelve a consultarse.
	 *
	 * @param array<string,mixed> $selection
	 * @return list<int>
	 * @throws \RuntimeException Si la consulta falla (el llamador decide si aborta el lote).
	 */
	private static function candidates( array $selection, int $afterId, int $limit, DateTimeImmutable $now ): array {
		if ( self::SELECTION_LIST === (string) ( $selection['type'] ?? '' ) ) {
			$ids = array();
			foreach ( (array) ( $selection['ids'] ?? array() ) as $id ) {
				$id = (int) $id;
				if ( $id > $afterId ) {
					$ids[ $id ] = $id;
				}
			}
			ksort( $ids );

			return array_slice( array_values( $ids ), 0, max( 1, $limit ) );
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$query = self::candidateQuery( $selection, false, $afterId, $limit, $now );
		if ( '' === $query['sql'] ) {
			return array();
		}

		$rows = $wpdb->get_col( $wpdb->prepare( $query['sql'], $query['args'] ) );
		if (
			! is_array( $rows )
			|| ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error )
		) {
			self::log( 'no se pudieron leer los candidatos del lote.' );
			throw new \RuntimeException( 'batch_candidates_failed' );
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$id = (int) $row;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/** Total de la selección. `-1` si la consulta falla. */
	private static function countCandidates( array $selection ): int {
		if ( self::SELECTION_LIST === (string) ( $selection['type'] ?? '' ) ) {
			return count( (array) ( $selection['ids'] ?? array() ) );
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return -1;
		}

		$query = self::candidateQuery( $selection, true, 0, 0, self::now() );
		if ( '' === $query['sql'] ) {
			return 0;
		}

		$count = $wpdb->get_var( $wpdb->prepare( $query['sql'], $query['args'] ) );
		if ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) {
			self::log( 'no se pudo contar la selección del lote.' );
			return -1;
		}

		return max( 0, (int) $count );
	}

	/**
	 * Consulta de candidatos. En modo conteo no lleva cursor ni límite; en modo página sí.
	 *
	 * @param array<string,mixed> $selection
	 * @return array{sql:string,args:list<mixed>}
	 */
	private static function candidateQuery(
		array $selection,
		bool $count,
		int $afterId,
		int $limit,
		DateTimeImmutable $now
	): array {
		global $wpdb;

		$roles = self::selectionRoles( $selection );
		if ( array() === $roles ) {
			return array( 'sql' => '', 'args' => array() );
		}

		$users    = isset( $wpdb->users ) && is_string( $wpdb->users ) ? $wpdb->users : $wpdb->prefix . 'users';
		$usermeta = isset( $wpdb->usermeta ) && is_string( $wpdb->usermeta )
			? $wpdb->usermeta
			: $wpdb->prefix . 'usermeta';
		$metaKey  = $wpdb->prefix . 'capabilities';
		$grants   = HWE_Memberships_Schema::grantsTable();

		$roleSql = array();
		foreach ( $roles as $role ) {
			// `LOCATE` compara la entrada serializada exacta (`"rol";b:1;`): sin comodines de
			// `LIKE` que confundirían un `_` del slug con «cualquier carácter».
			$roleSql[] = "LOCATE('\"{$role}\";b:1;', um.meta_value) > 0";
		}

		$type = (string) ( $selection['type'] ?? '' );
		$args = array();

		$sql = ( $count ? 'SELECT COUNT(DISTINCT u.ID)' : 'SELECT DISTINCT u.ID' )
			. " FROM {$users} u"
			. " INNER JOIN {$usermeta} um ON um.user_id = u.ID AND um.meta_key = '{$metaKey}'"
			. ' WHERE (' . implode( ' OR ', $roleSql ) . ')';

		if ( ! $count ) {
			$sql   .= ' AND u.ID > %d';
			$args[] = max( 0, $afterId );
		}

		if ( self::SELECTION_NO_MEMBERSHIP === $type ) {
			$sql   .= " AND NOT EXISTS (SELECT 1 FROM {$grants} g WHERE g.user_id = u.ID"
				. " AND g.status IN ('active','scheduled')"
				. ' AND (g.ends_at IS NULL OR g.ends_at > %s))';
			$args[] = self::sqlDate( $now );
		} elseif ( self::SELECTION_LEVEL === $type ) {
			$sql   .= " AND EXISTS (SELECT 1 FROM {$grants} g WHERE g.user_id = u.ID"
				. ' AND g.level_id = %d'
				. " AND g.status IN ('active','scheduled')"
				. ' AND (g.ends_at IS NULL OR g.ends_at > %s))';
			$args[] = (int) ( $selection['level_id'] ?? 0 );
			$args[] = self::sqlDate( $now );
		}

		if ( ! $count ) {
			$sql   .= ' ORDER BY u.ID ASC LIMIT %d';
			$args[] = max( 1, min( 1000, $limit ) );
		}

		return array( 'sql' => $sql, 'args' => $args );
	}

	/**
	 * Roles de la selección (los que fijó el lote o los actuales), saneados otra vez por si la
	 * fila guardada viniera de otro sitio.
	 *
	 * @param array<string,mixed> $selection
	 * @return list<string>
	 */
	private static function selectionRoles( array $selection ): array {
		$raw = isset( $selection['roles'] ) && is_array( $selection['roles'] )
			? $selection['roles']
			: HWE_Memberships_Settings::participantRoles();

		$roles = array();
		foreach ( $raw as $role ) {
			if ( ! is_scalar( $role ) ) {
				continue;
			}
			$role = strtolower( trim( (string) $role ) );
			$role = (string) preg_replace( '/[^a-z0-9_\-]/', '', $role );
			if ( '' !== $role ) {
				$roles[ $role ] = true;
			}
		}

		return array_keys( $roles );
	}

	// ------------------------------------------------------------------------
	//  Normalización de la petición
	// ------------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $selection
	 * @param array<string,mixed> $operation
	 * @return array<string,mixed>
	 */
	private static function normalizeRequest( array $selection, array $operation ): array {
		$normalizedSelection = self::normalizeSelection( $selection );
		if ( isset( $normalizedSelection['error'] ) ) {
			return $normalizedSelection;
		}

		$normalizedOperation = self::normalizeOperation( $operation );
		if ( isset( $normalizedOperation['error'] ) ) {
			return $normalizedOperation;
		}

		return array(
			'selection' => $normalizedSelection['selection'],
			'operation' => $normalizedOperation['operation'],
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array{selection:array<string,mixed>}|array{error:string,message:string}
	 */
	private static function normalizeSelection( array $raw ): array {
		$type = self::key( $raw['type'] ?? '' );
		if ( ! in_array( $type, self::selectionTypes(), true ) ) {
			return self::error( 'invalid_selection' );
		}

		if ( self::SELECTION_LIST === $type ) {
			$list  = isset( $raw['list'] ) && is_scalar( $raw['list'] ) ? (string) $raw['list'] : '';
			$ids   = isset( $raw['ids'] ) && is_array( $raw['ids'] ) ? $raw['ids'] : null;
			$list_ = self::resolveList( $list, $ids );
			if ( isset( $list_['error'] ) ) {
				return $list_;
			}

			return array(
				'selection' => array(
					'type'          => self::SELECTION_LIST,
					'ids'           => $list_['ids'],
					'missing'       => $list_['missing'],
					'missing_count' => $list_['missing_count'],
				),
			);
		}

		$levelId = absint( $raw['level_id'] ?? 0 );
		if ( self::SELECTION_LEVEL === $type ) {
			$level = $levelId > 0 ? HWE_Memberships_Levels::get( $levelId ) : null;
			if ( null === $level ) {
				return self::error( 'invalid_level' );
			}
		} else {
			$levelId = 0;
		}

		return array(
			'selection' => array(
				'type'     => $type,
				'level_id' => $levelId,
			),
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array{operation:array<string,mixed>}|array{error:string,message:string}
	 */
	private static function normalizeOperation( array $raw ): array {
		$type = self::key( $raw['type'] ?? '' );
		if ( ! in_array( $type, self::operationTypes(), true ) ) {
			return self::error( 'invalid_operation' );
		}

		$operation = array(
			'type'     => $type,
			'level_id' => 0,
			'days'     => 0,
			'mode'     => '',
		);

		if ( self::OPERATION_GRANT === $type ) {
			$levelId = absint( $raw['level_id'] ?? 0 );
			$level   = $levelId > 0 ? HWE_Memberships_Levels::get( $levelId ) : null;
			if ( null === $level || (int) ( $level['rank'] ?? 0 ) < 1 ) {
				return self::error( 'invalid_level' );
			}

			$days = array_key_exists( 'days', $raw ) && is_scalar( $raw['days'] )
				? (int) $raw['days']
				: 0;
			if ( $days < 0 || $days > self::MAX_DAYS ) {
				return self::error( 'invalid_days' );
			}

			$mode = self::key( $raw['mode'] ?? self::MODE_ONLY_NEW );
			if ( ! in_array( $mode, self::modes(), true ) ) {
				$mode = self::MODE_ONLY_NEW;
			}

			$operation['level_id'] = $levelId;
			$operation['days']     = $days;
			$operation['mode']     = $mode;
		}

		return array( 'operation' => $operation );
	}

	/**
	 * Resuelve una lista pegada de emails o ids. Devuelve los ids únicos, los tokens que no
	 * encontraron usuario y el número de entradas escritas.
	 *
	 * @param list<mixed>|null $ids Lista ya resuelta (uso interno); si llega, se ignora `$list`.
	 * @return array{ids:list<int>,missing:list<string>,missing_count:int}|array{error:string,message:string}
	 */
	private static function resolveList( string $list, ?array $ids = null ): array {
		if ( null !== $ids ) {
			$clean = array();
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$clean[ $id ] = $id;
				}
			}
			if ( count( $clean ) > self::LIST_MAX ) {
				return self::error( 'list_too_long' );
			}

			return array(
				'ids'           => array_values( $clean ),
				'missing'       => array(),
				'missing_count' => 0,
			);
		}

		$parts = preg_split( '/[\s,;]+/', $list );
		$parts = is_array( $parts ) ? $parts : array();
		$parts = array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);

		if ( array() === $parts ) {
			return self::error( 'empty_list' );
		}
		if ( count( $parts ) > self::LIST_MAX ) {
			return self::error( 'list_too_long' );
		}

		$resolved = array();
		$missing  = array();

		foreach ( $parts as $token ) {
			$token = function_exists( 'sanitize_text_field' )
				? (string) sanitize_text_field( $token )
				: $token;
			if ( '' === $token ) {
				continue;
			}

			$user = false;

			if ( ctype_digit( $token ) ) {
				$id   = (int) $token;
				$user = $id > 0 && function_exists( 'get_userdata' ) ? get_userdata( $id ) : false;
			} else {
				$email = strtolower( $token );
				$valid = function_exists( 'is_email' )
					? (bool) is_email( $email )
					: false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
				if ( $valid && function_exists( 'get_user_by' ) ) {
					$user = get_user_by( 'email', $email );
				}
			}

			$id = is_object( $user ) ? (int) ( $user->ID ?? 0 ) : 0;
			if ( $id > 0 ) {
				$resolved[ $id ] = $id;
			} elseif ( ! in_array( $token, $missing, true ) ) {
				$missing[] = $token;
			}
		}

		return array(
			'ids'           => array_values( $resolved ),
			'missing'       => $missing,
			'missing_count' => count( $missing ),
		);
	}

	/** @return list<string> */
	private static function selectionTypes(): array {
		return array(
			self::SELECTION_PARTICIPANTS,
			self::SELECTION_NO_MEMBERSHIP,
			self::SELECTION_LEVEL,
			self::SELECTION_LIST,
		);
	}

	/** @return list<string> */
	private static function operationTypes(): array {
		return array(
			self::OPERATION_GRANT,
			self::OPERATION_REMOVE,
			self::OPERATION_FREEZE,
			self::OPERATION_UNFREEZE,
		);
	}

	/** @return list<string> */
	private static function modes(): array {
		return array( self::MODE_ONLY_NEW, self::MODE_EXTEND_SAME, self::MODE_REPLACE );
	}

	/** @param array<string,mixed> $operation @return array<string,int> */
	private static function emptyCounts( array $operation ): array {
		$type = (string) $operation['type'];

		if ( self::OPERATION_GRANT === $type ) {
			if ( self::MODE_EXTEND_SAME === (string) $operation['mode'] ) {
				$keys = array( 'grant', 'skip_frozen', 'skip_other_level', 'skip_no_membership' );
			} elseif ( self::MODE_REPLACE === (string) $operation['mode'] ) {
				$keys = array( 'replace', 'grant' );
			} else {
				$keys = array( 'grant', 'skip_has_membership' );
			}
		} elseif ( self::OPERATION_REMOVE === $type ) {
			$keys = array( 'remove', 'skip_no_membership' );
		} elseif ( self::OPERATION_FREEZE === $type ) {
			$keys = array( 'freeze', 'skip_frozen', 'skip_no_membership' );
		} elseif ( self::OPERATION_UNFREEZE === $type ) {
			$keys = array( 'unfreeze', 'skip_not_frozen' );
		} else {
			$keys = array();
		}

		return array_fill_keys( $keys, 0 );
	}

	/** ¿El caso cambió algo? */
	private static function isApplyCase( string $case ): bool {
		return in_array(
			$case,
			array(
				'grant',
				self::MODE_REPLACE,
				self::OPERATION_REMOVE,
				self::OPERATION_FREEZE,
				self::OPERATION_UNFREEZE,
			),
			true
		);
	}

	/** ¿El caso es una omisión? */
	private static function isSkipCase( string $case ): bool {
		return str_starts_with( $case, 'skip_' );
	}

	// ------------------------------------------------------------------------
	//  Persistencia e integración
	// ------------------------------------------------------------------------

	/** @param array<string,mixed> $data */
	private static function insertBatch( array $data ): int {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}

		$inserted = $wpdb->insert( HWE_Memberships_Schema::batchesTable(), $data );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/** @param array<string,mixed> $data */
	private static function updateBatch( int $batchId, array $data ): bool {
		global $wpdb;

		if ( $batchId <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		return false !== $wpdb->update(
			HWE_Memberships_Schema::batchesTable(),
			$data,
			array( 'id' => $batchId )
		);
	}

	/** @return array<string,mixed>|null */
	private static function find( string $batchId ): ?array {
		global $wpdb;

		$id = (int) $batchId;
		if ( $id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . HWE_Memberships_Schema::batchesTable() . ' WHERE id = %d LIMIT 1',
				$id
			)
		);

		return is_object( $row ) ? (array) $row : null;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private static function normalizeRow( array $row ): array {
		$status    = self::key( $row['status'] ?? self::STATUS_PENDING );
		$total     = max( 0, (int) ( $row['total'] ?? 0 ) );
		$processed = max( 0, (int) ( $row['processed'] ?? 0 ) );
		$percent   = $total > 0
			? max( 0, min( 100, (int) floor( $processed * 100 / $total ) ) )
			: ( self::STATUS_DONE === $status ? 100 : 0 );

		$params = self::decode( $row['params'] ?? null );

		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'operation'     => self::key( $row['operation'] ?? '' ),
			'status'        => $status,
			'total'         => $total,
			'processed'     => $processed,
			'applied'       => max( 0, (int) ( $row['applied'] ?? 0 ) ),
			'percent'       => $percent,
			'cursor'        => max( 0, (int) ( $row['last_user_id'] ?? 0 ) ),
			'actor_id'      => (int) ( $row['actor_id'] ?? 0 ),
			'created_at'    => (string) ( $row['created_at'] ?? '' ),
			'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
			'finished_at'   => null === ( $row['finished_at'] ?? null ) ? null : (string) $row['finished_at'],
			'selection'     => self::decode( $row['selection'] ?? null ),
			'params'        => $params,
			'operation_data' => isset( $params['operation'] ) && is_array( $params['operation'] )
				? $params['operation']
				: array(),
			'missing_count' => (int) ( $params['missing_count'] ?? 0 ),
		);
	}

	private static function failBatch( int $batchId ): void {
		$nowSql = self::sqlDate( self::now() );
		if ( ! self::updateBatch(
			$batchId,
			array(
				'status'      => self::STATUS_FAILED,
				'updated_at'  => $nowSql,
				'finished_at' => $nowSql,
			)
		) ) {
			self::log( 'no se pudo marcar como fallido el lote ' . $batchId . '.' );
		}
	}

	private static function enqueue( string $batchId ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		try {
			$actionId = as_enqueue_async_action( self::HOOK, array( $batchId ), self::GROUP );
		} catch ( \Throwable $e ) {
			self::log( 'Action Scheduler rechazó el lote ' . (int) $batchId . '.' );
			return false;
		}

		return is_numeric( $actionId ) && (int) $actionId > 0;
	}

	private static function scheduleContinuation( int $batchId ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			self::log( 'no se pudo programar la continuación del lote ' . $batchId . ': falta Action Scheduler.' );
			return false;
		}

		try {
			$actionId = as_schedule_single_action(
				time() + 1,
				self::HOOK,
				array( (string) $batchId ),
				self::GROUP
			);
		} catch ( \Throwable $e ) {
			self::log( 'no se pudo programar la continuación del lote ' . $batchId . '.' );
			return false;
		}

		if ( ! is_numeric( $actionId ) || (int) $actionId <= 0 ) {
			self::log( 'no se pudo programar la continuación del lote ' . $batchId . '.' );
			return false;
		}

		return true;
	}

	/** Clave idempotente de la concesión: `bulk:<lote>:<usuario>`. */
	private static function grantKey( int $batchId, int $userId ): string {
		return self::KEY_PREFIX . $batchId . ':' . $userId;
	}

	/** ¿La concesión con esa clave ya existe? Consulta la tabla, no la caché. */
	private static function grantExists( string $key ): bool {
		global $wpdb;

		if ( '' === $key || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}

		$id = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . HWE_Memberships_Schema::grantsTable() . ' WHERE idempotency_key = %s LIMIT 1',
				$key
			)
		);

		return null !== $id && (int) $id > 0;
	}

	// ------------------------------------------------------------------------
	//  Utilidades
	// ------------------------------------------------------------------------

	/** @param array<string,mixed> $payload */
	private static function previewError( array $payload ): array {
		return array_merge(
			$payload,
			array(
				'selection'     => array(),
				'operation'     => array(),
				'total'         => 0,
				'affected'      => 0,
				'skipped'       => 0,
				'counts'        => array(),
				'missing'       => array(),
				'missing_count' => 0,
				'truncated'     => false,
				'warnings'      => array(),
			)
		);
	}

	/** @return array{error:string,message:string} */
	private static function error( string $code ): array {
		return array(
			'error'   => $code,
			'message' => self::errorMessage( $code ),
		);
	}

	/** @param array<string,mixed> $error */
	private static function wpError( array $error ): \WP_Error {
		return new \WP_Error(
			'hwe_memberships_' . (string) $error['error'],
			(string) $error['message']
		);
	}

	private static function errorMessage( string $code ): string {
		return match ( $code ) {
			'invalid_selection' => __( 'La selección no es válida.', 'hwe' ),
			'invalid_operation' => __( 'La operación no es válida.', 'hwe' ),
			'invalid_level'     => __( 'El nivel indicado no existe.', 'hwe' ),
			'invalid_days'      => __( 'Indica una duración válida en días.', 'hwe' ),
			'empty_list'        => __( 'La lista está vacía.', 'hwe' ),
			'list_too_long'     => sprintf(
				/* translators: %d: máximo de entradas de la lista. */
				__( 'La lista supera el máximo de %d entradas.', 'hwe' ),
				self::LIST_MAX
			),
			'empty_selection'   => __( 'La selección no contiene ningún usuario.', 'hwe' ),
			'no_participants'   => __( 'No hay roles participantes configurados.', 'hwe' ),
			'as_unavailable'    => __( 'Action Scheduler no está disponible; el lote no puede ejecutarse.', 'hwe' ),
			'enqueue_failed'    => __( 'No se pudo poner el lote en cola.', 'hwe' ),
			'store_failed'      => __( 'No se pudo guardar el lote.', 'hwe' ),
			'query_failed'      => __( 'No se pudo leer la selección.', 'hwe' ),
			default             => __( 'No se pudo completar la operación.', 'hwe' ),
		};
	}

	private static function key( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $value );
	}

	private static function json( array $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		return is_string( $json ) ? $json : '{}';
	}

	/** @return array<string,mixed> */
	private static function decode( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			self::log( 'se encontró un JSON corrupto en la tabla de lotes.' );
			return array();
		}

		return $decoded;
	}

	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( '@' . time() );
	}

	private static function sqlDate( DateTimeImmutable $date ): string {
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function log( string $message ): void {
		error_log( '[hwe] memberships: ' . $message );
	}
}
