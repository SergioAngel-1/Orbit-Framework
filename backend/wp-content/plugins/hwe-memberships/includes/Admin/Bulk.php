<?php
/**
 * Pantalla «En masa» del módulo (spec §11.3): selección, vista previa y confirmación.
 *
 * El flujo es de dos pasos y siempre con nonce:
 *  1. el formulario se envía a esta misma página y `render()` calcula la vista previa **sin
 *     escribir nada** (afectados por caso y tokens que no encontraron usuario);
 *  2. la vista previa pinta un segundo formulario que va a `admin-post.php` con su propio nonce
 *     y arranca el lote (`HWE_Memberships_Bulk::start()`), que encola la primera página en
 *     Action Scheduler.
 *
 * La pantalla nunca guarda la lista pegada ni los ids: el texto de la lista viaja en el
 * formulario de confirmación y el lote guarda la selección resuelta en su propia fila. La
 * capacidad `hwe_memberships_manage` se exige en las dos escrituras (`start` y `resume`); la de
 * lectura abre la página.
 *
 * El progreso se lee de `HWE_Memberships_Bulk::progress()` y se refresca solo mientras el lote
 * está en cola o en curso. Un lote fallido se puede reanudar desde la misma pantalla.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Bulk_Screen {

	/** Acción de `admin-post.php` que arranca el lote confirmado. */
	public const ACTION_START = 'hwe_memberships_bulk_start';

	/** Acción de `admin-post.php` que reanuda un lote fallido. */
	public const ACTION_RESUME = 'hwe_memberships_bulk_resume';

	/** Acción del nonce del arranque. */
	public const NONCE_START = 'hwe_memberships_bulk_start';

	/** Prefijo del nonce de reanudación (se ata al id del lote). */
	public const NONCE_RESUME_PREFIX = 'hwe_memberships_bulk_resume:';

	/** Acción del nonce de la vista previa (pantalla, misma página). */
	public const NONCE_PREVIEW = 'hwe_memberships_bulk_preview';

	/** Campo del nonce en los formularios. */
	public const NONCE_FIELD = 'hwe_memberships_bulk_nonce';

	/** Lotes que muestra el historial reciente. */
	public const RECENT = 15;

	/** Engancha las escrituras. La llama `HWE_Memberships_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_START, array( self::class, 'handleStart' ) );
		add_action( 'admin_post_' . self::ACTION_RESUME, array( self::class, 'handleResume' ) );
	}

	// ------------------------------------------------------------------------
	//  Pantalla
	// ------------------------------------------------------------------------

	/** Página del módulo. El POST de vista previa se queda aquí; nada se escribe sin confirmar. */
	public static function render(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			wp_die( esc_html__( 'No tienes permiso para ver las operaciones en masa.', 'hwe' ) );
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Operaciones en masa', 'hwe' ) . '</h1>';

		HWE_Memberships_Admin::renderNotice( HWE_Memberships_Admin::pullFlash() );

		$batchId = isset( $_GET['batch'] ) && is_scalar( $_GET['batch'] ) ? absint( wp_unslash( $_GET['batch'] ) ) : 0;
		if ( $batchId > 0 ) {
			self::renderProgress( \HWE_Memberships_Bulk::progress( (string) $batchId ) );
		}

		$request = self::request( array() );
		$preview = null;

		if ( isset( $_POST['hwe_bulk_preview'] ) ) {
			$nonce = self::posted( self::NONCE_FIELD );
			if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_PREVIEW ) ) {
				echo '<div class="notice notice-error"><p>'
					. esc_html__( 'El formulario caducó. Recarga la pantalla e inténtalo de nuevo.', 'hwe' )
					. '</p></div>';
			} else {
				$request = self::request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
				$preview = \HWE_Memberships_Bulk::preview( $request['selection'], $request['operation'] );
			}
		}

		if ( is_array( $preview ) && ! isset( $preview['error'] ) ) {
			self::renderPreview( $preview, $request );
		} elseif ( is_array( $preview ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( (string) ( $preview['message'] ?? __( 'La selección no es válida.', 'hwe' ) ) )
				. '</p></div>';
			self::renderForm( $request );
		} else {
			self::renderForm( $request );
		}

		self::renderRecent();
		echo '</div>';
	}

	/** Formulario de selección y operación. */
	public static function renderForm( array $request ): void {
		$selection = isset( $request['selection'] ) && is_array( $request['selection'] ) ? $request['selection'] : array();
		$operation = isset( $request['operation'] ) && is_array( $request['operation'] ) ? $request['operation'] : array();

		$selectionType = (string) ( $selection['type'] ?? '' );
		$selectionMenu = self::selectionLabels();
		if ( ! isset( $selectionMenu[ $selectionType ] ) ) {
			$selectionType = \HWE_Memberships_Bulk::SELECTION_PARTICIPANTS;
		}

		$operationType = (string) ( $operation['type'] ?? '' );
		$operationMenu = self::operationLabels();
		if ( ! isset( $operationMenu[ $operationType ] ) ) {
			$operationType = \HWE_Memberships_Bulk::OPERATION_GRANT;
		}

		$mode     = (string) ( $operation['mode'] ?? '' );
		$modeMenu = self::modeLabels();
		if ( ! isset( $modeMenu[ $mode ] ) ) {
			$mode = \HWE_Memberships_Bulk::MODE_ONLY_NEW;
		}

		echo '<h2>' . esc_html__( 'Nueva operación', 'hwe' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::BULK ) ) . '">';
		wp_nonce_field( self::NONCE_PREVIEW, self::NONCE_FIELD );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="hwe-bulk-selection">' . esc_html__( 'Selección', 'hwe' ) . '</label></th><td>';
		echo '<select id="hwe-bulk-selection" name="hwe_bulk_selection">';
		foreach ( self::selectionLabels() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				$key === $selectionType ? ' selected="selected"' : '',
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( '«Todos los participantes» usa los roles configurados del programa.', 'hwe' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hwe-bulk-selection-level">' . esc_html__( 'Nivel de la selección', 'hwe' ) . '</label></th><td>';
		self::renderLevelSelect( 'hwe_bulk_selection_level', (int) ( $selection['level_id'] ?? 0 ), __( 'Cualquier nivel', 'hwe' ) );
		echo '<p class="description">' . esc_html__( 'Solo se aplica a la selección «De un nivel».', 'hwe' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hwe-bulk-list">' . esc_html__( 'Lista de emails o ids', 'hwe' ) . '</label></th><td>';
		printf(
			'<textarea id="hwe-bulk-list" name="hwe_bulk_list" rows="4" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr__( 'uno@ejemplo.test, 42, 43', 'hwe' ),
			esc_html( (string) ( $selection['list'] ?? '' ) )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf(
				/* translators: %d: máximo de entradas. */
				__( 'Solo se aplica a la selección «Lista pegada». Separa las entradas con comas o saltos de línea; máximo %d.', 'hwe' ),
				\HWE_Memberships_Bulk::LIST_MAX
			) )
		);
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hwe-bulk-operation">' . esc_html__( 'Operación', 'hwe' ) . '</label></th><td>';
		echo '<select id="hwe-bulk-operation" name="hwe_bulk_operation">';
		foreach ( self::operationLabels() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				$key === $operationType ? ' selected="selected"' : '',
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( '«Quitar» retira todas las membresías vivas del socio; congelar y descongelar actúan sobre su membresía completa.', 'hwe' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hwe-bulk-level">' . esc_html__( 'Nivel a asignar', 'hwe' ) . '</label></th><td>';
		self::renderLevelSelect( 'hwe_bulk_level', (int) ( $operation['level_id'] ?? 0 ), __( 'Elige un nivel', 'hwe' ) );
		echo '<p class="description">' . esc_html__( 'Solo se aplica a la operación «Asignar».', 'hwe' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hwe-bulk-days">' . esc_html__( 'Duración en días', 'hwe' ) . '</label></th><td>';
		printf(
			'<input type="number" id="hwe-bulk-days" name="hwe_bulk_days" min="0" step="1" value="%s" class="small-text">',
			esc_attr( (string) (int) ( $operation['days'] ?? 0 ) )
		);
		echo '<p class="description">' . esc_html__( '0 = membresía indefinida.', 'hwe' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hwe-bulk-mode">' . esc_html__( 'Modo de asignación', 'hwe' ) . '</label></th><td>';
		echo '<select id="hwe-bulk-mode" name="hwe_bulk_mode">';
		foreach ( self::modeLabels() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				$key === $mode ? ' selected="selected"' : '',
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<p class="submit">';
		printf(
			'<button type="submit" name="hwe_bulk_preview" value="1" class="button button-primary">%s</button>',
			esc_html__( 'Previsualizar', 'hwe' )
		);
		echo '</p>';
		echo '</form>';
	}

	/**
	 * Vista previa con el recuento por caso, los no encontrados y la confirmación explícita.
	 *
	 * @param array<string,mixed> $preview
	 * @param array<string,mixed> $request
	 */
	public static function renderPreview( array $preview, array $request ): void {
		$counts  = isset( $preview['counts'] ) && is_array( $preview['counts'] ) ? $preview['counts'] : array();
		$missing = isset( $preview['missing'] ) && is_array( $preview['missing'] ) ? $preview['missing'] : array();
		$total   = (int) ( $preview['total'] ?? 0 );
		$affected = (int) ( $preview['affected'] ?? 0 );
		$skipped = (int) ( $preview['skipped'] ?? 0 );

		echo '<div class="card" style="max-width:900px;padding:0 1em 1em">';
		echo '<h2>' . esc_html__( 'Vista previa', 'hwe' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html( sprintf(
				/* translators: 1: usuarios revisados, 2: afectados, 3: omitidos. */
				__( 'Revisados %1$d socios: %2$d cambios y %3$d omisiones.', 'hwe' ),
				$total,
				$affected,
				$skipped
			) )
		);

		echo '<table class="widefat striped"><tbody>';
		foreach ( $counts as $case => $count ) {
			printf(
				'<tr><th scope="row">%s</th><td>%d</td></tr>',
				esc_html( self::caseLabel( (string) $case ) ),
				(int) $count
			);
		}
		echo '</tbody></table>';

		if ( array() !== $missing ) {
			printf(
				'<h3>%s</h3>',
				esc_html( sprintf(
					/* translators: %d: entradas de la lista que no encontraron usuario. */
					__( 'No encontrados (%d)', 'hwe' ),
					count( $missing )
				) )
			);
			echo '<ul class="ul-disc">';
			foreach ( $missing as $token ) {
				echo '<li>' . esc_html( (string) $token ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $preview['truncated'] ) ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html( sprintf(
					/* translators: %d: número de socios revisados en la vista previa. */
					__( 'La vista previa se limita a los primeros %d socios; el lote continuará con el resto de la selección.', 'hwe' ),
					\HWE_Memberships_Bulk::PREVIEW_MAX
				) )
			);
		}

		if ( ! empty( $preview['warnings'] ) && is_array( $preview['warnings'] ) && in_array( 'preview_truncated', $preview['warnings'], true ) ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Los recuentos son un mínimo: hay más socios en la selección.', 'hwe' )
			);
		}

		$selection = isset( $request['selection'] ) && is_array( $request['selection'] ) ? $request['selection'] : array();
		$operation = isset( $request['operation'] ) && is_array( $request['operation'] ) ? $request['operation'] : array();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_START ) . '">';
		echo '<input type="hidden" name="hwe_bulk_confirm" value="1">';
		self::hidden( 'hwe_bulk_selection', (string) ( $selection['type'] ?? '' ) );
		self::hidden( 'hwe_bulk_selection_level', (string) (int) ( $selection['level_id'] ?? 0 ) );
		self::hidden( 'hwe_bulk_list', (string) ( $selection['list'] ?? '' ) );
		self::hidden( 'hwe_bulk_operation', (string) ( $operation['type'] ?? '' ) );
		self::hidden( 'hwe_bulk_level', (string) (int) ( $operation['level_id'] ?? 0 ) );
		self::hidden( 'hwe_bulk_days', (string) (int) ( $operation['days'] ?? 0 ) );
		self::hidden( 'hwe_bulk_mode', (string) ( $operation['mode'] ?? '' ) );
		wp_nonce_field( self::NONCE_START, self::NONCE_FIELD );

		echo '<p class="submit">';
		printf(
			'<button type="submit" class="button button-primary"%s>%s</button>',
			$affected > 0 ? '' : ' disabled="disabled"',
			esc_html__( 'Confirmar y ejecutar', 'hwe' )
		);
		echo '</p>';

		if ( $affected <= 0 ) {
			echo '<p class="description">' . esc_html__( 'No hay socios que cumplan la selección.', 'hwe' ) . '</p>';
		}

		echo '</form>';
		echo '</div>';
	}

	/** Tarjeta de progreso de un lote concreto. */
	public static function renderProgress( array $progress ): void {
		if ( isset( $progress['error'] ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'El lote indicado no existe.', 'hwe' )
				. '</p></div>';
			return;
		}

		$status = (string) ( $progress['status'] ?? '' );
		$id     = (int) ( $progress['id'] ?? 0 );

		echo '<div class="card" style="max-width:900px;padding:0 1em 1em">';
		printf(
			'<h2>%s</h2>',
			esc_html( sprintf(
				/* translators: %d: id del lote. */
				__( 'Lote #%d', 'hwe' ),
				$id
			) )
		);
		echo '<table class="widefat striped"><tbody>';
		foreach ( self::progressRows( $progress ) as $label => $value ) {
			printf(
				'<tr><th scope="row">%s</th><td>%s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}
		echo '</tbody></table>';

		if ( \HWE_Memberships_Bulk::STATUS_DONE !== $status ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESUME ) . '">';
			echo '<input type="hidden" name="batch" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( self::resumeNonce( $id ), self::NONCE_FIELD );
			printf(
				'<button type="submit" class="button">%s</button>',
				esc_html__( 'Reanudar el lote', 'hwe' )
			);
			echo '<p class="description">' . esc_html__( 'Si el lote se quedó detenido, vuelve a ponerlo en cola desde su último progreso.', 'hwe' ) . '</p>';
			echo '</form>';
		}

		echo '</div>';

		if ( in_array( $status, array( \HWE_Memberships_Bulk::STATUS_PENDING, \HWE_Memberships_Bulk::STATUS_RUNNING ), true ) ) {
			echo '<script>window.setTimeout(function(){window.location.reload();},5000);</script>';
		}
	}

	/** Historial reciente de lotes con su progreso. */
	public static function renderRecent(): void {
		$batches = \HWE_Memberships_Bulk::recent( self::RECENT );

		echo '<h2>' . esc_html__( 'Lotes recientes', 'hwe' ) . '</h2>';

		if ( array() === $batches ) {
			echo '<p><em>' . esc_html__( 'Todavía no hay operaciones en masa.', 'hwe' ) . '</em></p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Lote', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Operación', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Estado', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Progreso', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Cambios', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Fecha', 'hwe' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $batches as $batch ) {
			$id = (int) ( $batch['id'] ?? 0 );
			echo '<tr>';
			printf(
				'<td><a href="%s">#%d</a></td>',
				esc_url( self::url( array( 'batch' => $id ) ) ),
				$id
			);
			echo '<td>' . esc_html( self::operationLabel( (string) ( $batch['operation'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( self::statusLabel( (string) ( $batch['status'] ?? '' ) ) ) . '</td>';
			printf(
				'<td>%s</td>',
				esc_html( sprintf(
					/* translators: 1: socios procesados, 2: total de la selección. */
					__( '%1$d de %2$d', 'hwe' ),
					(int) ( $batch['processed'] ?? 0 ),
					(int) ( $batch['total'] ?? 0 )
				) )
			);
			echo '<td>' . esc_html( (string) (int) ( $batch['applied'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( self::formatDate( (string) ( $batch['created_at'] ?? '' ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// ------------------------------------------------------------------------
	//  Escrituras
	// ------------------------------------------------------------------------

	/** POST de confirmación: capacidad, nonce y arranque del lote. */
	public static function handleStart(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canManage() ) {
			wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
			return;
		}

		$nonce = self::posted( self::NONCE_FIELD );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_START ) ) {
			wp_die( esc_html__( 'Nonce no válido.', 'hwe' ) );
			return;
		}

		$request = self::request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$actorId = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$result  = \HWE_Memberships_Bulk::start( $request['selection'], $request['operation'], $actorId );

		$batchId = 0;
		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();
			HWE_Memberships_Admin::flash(
				match ( $code ) {
					'hwe_memberships_as_unavailable'                        => 'bulk_no_scheduler',
					'hwe_memberships_list_too_long'                         => 'bulk_too_many',
					'hwe_memberships_empty_selection',
					'hwe_memberships_no_participants'                       => 'bulk_empty',
					'hwe_memberships_enqueue_failed',
					'hwe_memberships_store_failed',
					'hwe_memberships_query_failed'                          => 'bulk_not_started',
					default                                                 => 'bulk_invalid',
				}
			);
		} else {
			$batchId = (int) $result;
			HWE_Memberships_Admin::flash( 'bulk_started', array( 'batch' => $batchId ) );
		}

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( self::url( $batchId > 0 ? array( 'batch' => $batchId ) : array() ) );
		}
	}

	/** POST de reanudación de un lote fallido. */
	public static function handleResume(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canManage() ) {
			wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
			return;
		}

		$batchId = isset( $_POST['batch'] ) && is_scalar( $_POST['batch'] )
			? absint( wp_unslash( $_POST['batch'] ) )
			: 0;
		$nonce   = self::posted( self::NONCE_FIELD );

		if ( $batchId <= 0 || '' === $nonce || ! wp_verify_nonce( $nonce, self::resumeNonce( $batchId ) ) ) {
			wp_die( esc_html__( 'Nonce no válido.', 'hwe' ) );
			return;
		}

		if ( ! \HWE_Memberships_Bulk::schedulerAvailable() ) {
			HWE_Memberships_Admin::flash( 'bulk_no_scheduler', array( 'batch' => $batchId ) );
		} else {
			$resumed = \HWE_Memberships_Bulk::resume( (string) $batchId );
			HWE_Memberships_Admin::flash( $resumed ? 'bulk_resumed' : 'bulk_not_found', array( 'batch' => $batchId ) );
		}

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( self::url( array( 'batch' => $batchId ) ) );
		}
	}

	// ------------------------------------------------------------------------
	//  Petición y utilidades
	// ------------------------------------------------------------------------

	/**
	 * Campos del formulario, saneados. La lista se conserva como texto: el lote la resolverá a
	 * ids al arrancar y la vista previa la resolverá solo para contar.
	 *
	 * @param array<string,mixed> $source
	 * @return array{selection:array<string,mixed>,operation:array<string,mixed>}
	 */
	public static function request( array $source ): array {
		$scalar = static function ( string $key ) use ( $source ): string {
			if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
				return '';
			}
			return function_exists( 'wp_unslash' )
				? (string) wp_unslash( $source[ $key ] )
				: (string) $source[ $key ];
		};

		$list = $scalar( 'hwe_bulk_list' );
		$list = function_exists( 'sanitize_textarea_field' )
			? (string) sanitize_textarea_field( $list )
			: trim( strip_tags( $list ) );

		return array(
			'selection' => array(
				'type'     => sanitize_key( $scalar( 'hwe_bulk_selection' ) ),
				'level_id' => absint( $scalar( 'hwe_bulk_selection_level' ) ),
				'list'     => $list,
			),
			'operation' => array(
				'type'     => sanitize_key( $scalar( 'hwe_bulk_operation' ) ),
				'level_id' => absint( $scalar( 'hwe_bulk_level' ) ),
				'days'     => (int) $scalar( 'hwe_bulk_days' ),
				'mode'     => sanitize_key( $scalar( 'hwe_bulk_mode' ) ),
			),
		);
	}

	/** URL de la pantalla. @param array<string,mixed> $args */
	public static function url( array $args = array() ): string {
		return HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::BULK, $args );
	}

	/** Acción del nonce de reanudación de un lote. */
	public static function resumeNonce( int $batchId ): string {
		return self::NONCE_RESUME_PREFIX . $batchId;
	}

	/** @return array<string,string> */
	public static function selectionLabels(): array {
		return array(
			\HWE_Memberships_Bulk::SELECTION_PARTICIPANTS  => __( 'Todos los participantes', 'hwe' ),
			\HWE_Memberships_Bulk::SELECTION_NO_MEMBERSHIP => __( 'Sin membresía', 'hwe' ),
			\HWE_Memberships_Bulk::SELECTION_LEVEL         => __( 'De un nivel', 'hwe' ),
			\HWE_Memberships_Bulk::SELECTION_LIST          => __( 'Lista pegada de emails o ids', 'hwe' ),
		);
	}

	/** @return array<string,string> */
	public static function operationLabels(): array {
		return array(
			\HWE_Memberships_Bulk::OPERATION_GRANT    => __( 'Asignar', 'hwe' ),
			\HWE_Memberships_Bulk::OPERATION_REMOVE   => __( 'Quitar', 'hwe' ),
			\HWE_Memberships_Bulk::OPERATION_FREEZE   => __( 'Congelar', 'hwe' ),
			\HWE_Memberships_Bulk::OPERATION_UNFREEZE => __( 'Descongelar', 'hwe' ),
		);
	}

	/** @return array<string,string> */
	public static function modeLabels(): array {
		return array(
			\HWE_Memberships_Bulk::MODE_ONLY_NEW    => __( 'Solo a quien no tiene membresía', 'hwe' ),
			\HWE_Memberships_Bulk::MODE_EXTEND_SAME => __( 'Extender a quien ya tiene ese nivel', 'hwe' ),
			\HWE_Memberships_Bulk::MODE_REPLACE     => __( 'Reemplazar (quita lo que tenga y concede)', 'hwe' ),
		);
	}

	/** Etiqueta de un caso de la vista previa. */
	public static function caseLabel( string $case ): string {
		return match ( $case ) {
			'grant'                => __( 'Se concederá', 'hwe' ),
			'replace'              => __( 'Se reemplazará', 'hwe' ),
			'remove'               => __( 'Se quitará la membresía', 'hwe' ),
			'freeze'               => __( 'Se congelará', 'hwe' ),
			'unfreeze'             => __( 'Se descongelará', 'hwe' ),
			'skip_has_membership'  => __( 'Ya tiene membresía (se omite)', 'hwe' ),
			'skip_other_level'     => __( 'Tiene otro nivel (se omite)', 'hwe' ),
			'skip_no_membership'   => __( 'Sin membresía (se omite)', 'hwe' ),
			'skip_frozen'          => __( 'Congelado (se omite)', 'hwe' ),
			'skip_not_frozen'      => __( 'No está congelado (se omite)', 'hwe' ),
			'skip_already_applied' => __( 'Ya aplicado en este lote (se omite)', 'hwe' ),
			'failed'               => __( 'No se pudo aplicar', 'hwe' ),
			default                => __( 'Otro caso', 'hwe' ),
		};
	}

	// ------------------------------------------------------------------------
	//  Presentación de bajo nivel
	// ------------------------------------------------------------------------

	/** @param array<string,mixed> $progress @return array<string,string> */
	private static function progressRows( array $progress ): array {
		$selection = isset( $progress['selection'] ) && is_array( $progress['selection'] )
			? $progress['selection']
			: array();
		$operation = isset( $progress['operation_data'] ) && is_array( $progress['operation_data'] )
			? $progress['operation_data']
			: array();

		$rows = array(
			__( 'Estado', 'hwe' )     => self::statusLabel( (string) ( $progress['status'] ?? '' ) ),
			__( 'Operación', 'hwe' )  => self::operationLabel( (string) ( $progress['operation'] ?? '' ) ),
			__( 'Selección', 'hwe' )  => self::selectionSummary( $selection ),
			__( 'Progreso', 'hwe' )   => sprintf(
				/* translators: 1: socios procesados, 2: total, 3: porcentaje. */
				__( '%1$d de %2$d (%3$d%%)', 'hwe' ),
				(int) ( $progress['processed'] ?? 0 ),
				(int) ( $progress['total'] ?? 0 ),
				(int) ( $progress['percent'] ?? 0 )
			),
			__( 'Cambios aplicados', 'hwe' ) => (string) (int) ( $progress['applied'] ?? 0 ),
			__( 'Entradas no encontradas', 'hwe' ) => (string) (int) ( $progress['missing_count'] ?? 0 ),
		);

		if ( array() !== $operation ) {
			$rows[ __( 'Detalle', 'hwe' ) ] = self::operationSummary( $operation );
		}

		$rows[ __( 'Creado', 'hwe' ) ] = self::formatDate( (string) ( $progress['created_at'] ?? '' ) );

		if ( null !== ( $progress['finished_at'] ?? null ) ) {
			$rows[ __( 'Terminado', 'hwe' ) ] = self::formatDate( (string) $progress['finished_at'] );
		}

		return $rows;
	}

	/** @param array<string,mixed> $selection */
	public static function selectionSummary( array $selection ): string {
		$type = (string) ( $selection['type'] ?? '' );

		if ( \HWE_Memberships_Bulk::SELECTION_LEVEL === $type ) {
			$levelId = (int) ( $selection['level_id'] ?? 0 );
			$level   = $levelId > 0 ? HWE_Memberships_Levels::get( $levelId ) : null;

			return sprintf(
				/* translators: %s: nombre del nivel. */
				__( 'Nivel «%s»', 'hwe' ),
				null === $level ? '#' . $levelId : (string) $level['name']
			);
		}

		if ( \HWE_Memberships_Bulk::SELECTION_LIST === $type ) {
			$ids = isset( $selection['ids'] ) && is_array( $selection['ids'] ) ? count( $selection['ids'] ) : 0;

			return sprintf(
				/* translators: %d: usuarios resueltos de la lista. */
				__( 'Lista (%d usuarios)', 'hwe' ),
				$ids
			);
		}

		if ( \HWE_Memberships_Bulk::SELECTION_NO_MEMBERSHIP === $type ) {
			return __( 'Sin membresía', 'hwe' );
		}

		return __( 'Todos los participantes', 'hwe' );
	}

	/** @param array<string,mixed> $operation */
	public static function operationSummary( array $operation ): string {
		$type = (string) ( $operation['type'] ?? '' );

		if ( \HWE_Memberships_Bulk::OPERATION_GRANT !== $type ) {
			return self::operationLabel( $type );
		}

		$levelId = (int) ( $operation['level_id'] ?? 0 );
		$level   = $levelId > 0 ? HWE_Memberships_Levels::get( $levelId ) : null;
		$days    = (int) ( $operation['days'] ?? 0 );
		$mode    = (string) ( $operation['mode'] ?? '' );

		return sprintf(
			/* translators: 1: nombre del nivel, 2: duración, 3: modo. */
			__( '%1$s · %2$s · %3$s', 'hwe' ),
			null === $level ? '#' . $levelId : (string) $level['name'],
			0 === $days ? __( 'indefinida', 'hwe' ) : sprintf(
				/* translators: %d: días. */
				__( '%d días', 'hwe' ),
				$days
			),
			self::modeLabels()[ $mode ] ?? $mode
		);
	}

	public static function operationLabel( string $operation ): string {
		return self::operationLabels()[ $operation ] ?? $operation;
	}

	public static function statusLabel( string $status ): string {
		return match ( $status ) {
			\HWE_Memberships_Bulk::STATUS_PENDING => __( 'En cola', 'hwe' ),
			\HWE_Memberships_Bulk::STATUS_RUNNING => __( 'En curso', 'hwe' ),
			\HWE_Memberships_Bulk::STATUS_DONE    => __( 'Terminado', 'hwe' ),
			\HWE_Memberships_Bulk::STATUS_FAILED  => __( 'Fallido', 'hwe' ),
			default                               => __( 'Desconocido', 'hwe' ),
		};
	}

	/** Selector de niveles (incluye borradores: el administrador puede asignarlos). */
	private static function renderLevelSelect( string $name, int $selected, string $emptyLabel ): void {
		printf(
			'<select id="%1$s" name="%1$s"><option value="0">%2$s</option>',
			esc_attr( $name ),
			esc_html( $emptyLabel )
		);

		foreach ( HWE_Memberships_Levels::all( true ) as $level ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) (int) $level['id'] ),
				(int) $level['id'] === $selected ? ' selected="selected"' : '',
				esc_html( (string) $level['name'] )
			);
		}

		echo '</select>';
	}

	private static function hidden( string $name, string $value ): void {
		printf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( $name ),
			esc_attr( $value )
		);
	}

	private static function posted( string $name ): string {
		if ( ! isset( $_POST[ $name ] ) || ! is_scalar( $_POST[ $name ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_POST[ $name ] ) );
	}

	private static function formatDate( string $utc ): string {
		if ( '' === $utc ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );
		if ( false === $timestamp ) {
			return $utc;
		}

		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i', $timestamp ) : gmdate( 'Y-m-d H:i', $timestamp );
	}
}
