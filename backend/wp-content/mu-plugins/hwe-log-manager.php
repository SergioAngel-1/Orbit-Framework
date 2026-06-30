<?php
/**
 * Plugin Name: HWE Log Manager
 * Description: Rotación automática de debug.log y visor en tiempo real bajo
 *              Ajustes → HWE Logs. Configuración vía opciones hwe_logmgr_*.
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------ */

function hwe_logmgr_opt( string $key, mixed $default = null ): mixed {
	return get_option( 'hwe_logmgr_' . $key, $default );
}

function hwe_logmgr_debug_log(): string {
	return WP_CONTENT_DIR . '/debug.log';
}

/** Lista de archivos rotados ordenada de más reciente a más antiguo. */
function hwe_logmgr_rotated_files(): array {
	$files = glob( WP_CONTENT_DIR . '/debug-*.log' ) ?: [];
	usort( $files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );
	return $files;
}

/** Últimas $n líneas de un archivo sin cargarlo entero en memoria. */
function hwe_logmgr_tail( string $file, int $n ): string {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) return '';
	$block = 4096;
	$data  = '';
	fseek( $fh, 0, SEEK_END );
	$pos = ftell( $fh );
	while ( $pos > 0 && substr_count( $data, "\n" ) < $n ) {
		$read = min( $block, $pos );
		$pos -= $read;
		fseek( $fh, $pos );
		$data = fread( $fh, $read ) . $data;
	}
	fclose( $fh );
	return implode( "\n", array_slice( explode( "\n", $data ), -$n ) );
}

/* ---------------------------------------------------------------------------
 * 1) Rotación automática en shutdown
 * ------------------------------------------------------------------------ */

add_action( 'shutdown', function () {
	$log = hwe_logmgr_debug_log();
	if ( ! file_exists( $log ) ) return;

	$max = (int) hwe_logmgr_opt( 'max_size', 5242880 );
	if ( filesize( $log ) < $max ) return;

	rename( $log, WP_CONTENT_DIR . '/debug-' . date( 'Y-m-d-H-i-s' ) . '.log' );

	$cutoff    = time() - ( (int) hwe_logmgr_opt( 'retain_days', 7 ) * DAY_IN_SECONDS );
	$max_files = (int) hwe_logmgr_opt( 'max_files', 10 );
	$list      = hwe_logmgr_rotated_files();

	foreach ( $list as $f ) {
		if ( filemtime( $f ) < $cutoff ) @unlink( $f );
	}

	$list = hwe_logmgr_rotated_files();
	while ( count( $list ) > $max_files ) {
		@unlink( array_pop( $list ) ); // pop elimina el más antiguo (lista ordenada desc)
	}
}, 999 );

/* ---------------------------------------------------------------------------
 * 2) Menú admin
 * ------------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_options_page( 'HWE Logs', 'HWE Logs', 'manage_options', 'hwe-logs', 'hwe_logmgr_render_page' );
} );

add_action( 'admin_enqueue_scripts', function ( string $hook ) {
	if ( 'settings_page_hwe-logs' !== $hook ) return;
	wp_enqueue_script( 'jquery' );
	wp_localize_script( 'jquery', 'hweLog', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'hwe_get_log' ),
		'lines'   => (int) hwe_logmgr_opt( 'view_lines', 200 ),
	] );
	wp_add_inline_script( 'jquery', '(function($){
		function fetchLog(){
			$.post(hweLog.ajaxUrl,{action:"hwe_get_log",nonce:hweLog.nonce,lines:hweLog.lines},function(d){
				var p=document.getElementById("hwe-log-content");
				if(p){p.textContent=d;p.scrollTop=p.scrollHeight;}
			});
		}
		$(fetchLog);
		setInterval(fetchLog,5000);
	}(jQuery));' );
} );

/* ---------------------------------------------------------------------------
 * 3) Página de administración
 * ------------------------------------------------------------------------ */

function hwe_logmgr_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$notices = [
		'saved'       => [ 'success', 'Configuración guardada.' ],
		'cleared'     => [ 'success', 'Log actual borrado.' ],
		'deleted'     => [ 'success', 'Archivo eliminado.' ],
		'deleted_all' => [ 'success', 'Todos los archivos rotados eliminados.' ],
	];
	foreach ( $notices as $key => [ $type, $msg ] ) {
		if ( isset( $_GET[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $msg ) );
		}
	}

	$log     = hwe_logmgr_debug_log();
	$size    = file_exists( $log ) ? size_format( filesize( $log ) ) : '—';
	$rotated = hwe_logmgr_rotated_files();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'HWE Logs', 'hwe' ); ?></h1>

		<h2 class="title"><?php esc_html_e( 'Configuración', 'hwe' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hwe_save_logmgr_config' ); ?>
			<input type="hidden" name="action" value="hwe_save_logmgr_config">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="hwe_max_size"><?php esc_html_e( 'Tamaño máximo antes de rotar', 'hwe' ); ?></label></th>
					<td><select name="hwe_logmgr_max_size" id="hwe_max_size"><?php
						$cur = (int) hwe_logmgr_opt( 'max_size', 5242880 );
						foreach ( [ 1048576 => '1 MB', 5242880 => '5 MB', 10485760 => '10 MB', 52428800 => '50 MB' ] as $v => $l )
							printf( '<option value="%d"%s>%s</option>', $v, selected( $cur, $v, false ), esc_html( $l ) );
					?></select></td>
				</tr>
				<tr>
					<th><label for="hwe_retain"><?php esc_html_e( 'Días de retención', 'hwe' ); ?></label></th>
					<td><input type="number" name="hwe_logmgr_retain_days" id="hwe_retain" min="1" max="90" value="<?php echo esc_attr( hwe_logmgr_opt( 'retain_days', 7 ) ); ?>" class="small-text"> <?php esc_html_e( 'días', 'hwe' ); ?></td>
				</tr>
				<tr>
					<th><label for="hwe_max_files"><?php esc_html_e( 'Máx. archivos rotados', 'hwe' ); ?></label></th>
					<td><select name="hwe_logmgr_max_files" id="hwe_max_files"><?php
						$cur = (int) hwe_logmgr_opt( 'max_files', 10 );
						foreach ( [ 5 => '5', 10 => '10', 20 => '20', 50 => '50' ] as $v => $l )
							printf( '<option value="%d"%s>%s</option>', $v, selected( $cur, $v, false ), esc_html( $l ) );
					?></select></td>
				</tr>
				<tr>
					<th><label for="hwe_view_lines"><?php esc_html_e( 'Líneas en el visor', 'hwe' ); ?></label></th>
					<td><select name="hwe_logmgr_view_lines" id="hwe_view_lines"><?php
						$cur = (int) hwe_logmgr_opt( 'view_lines', 200 );
						foreach ( [ 100 => '100', 200 => '200', 500 => '500', 1000 => '1000' ] as $v => $l )
							printf( '<option value="%d"%s>%s</option>', $v, selected( $cur, $v, false ), esc_html( $l ) );
					?></select></td>
				</tr>
			</table>
			<?php submit_button( 'Guardar configuración' ); ?>
		</form>

		<hr>

		<h2 class="title"><?php esc_html_e( 'Log actual', 'hwe' ); ?> <small>(<?php echo esc_html( $size ); ?>)</small></h2>
		<pre id="hwe-log-content" style="font-family:monospace;font-size:12px;background:#1e1e1e;color:#d4d4d4;padding:12px;overflow:auto;max-height:500px;width:100%;box-sizing:border-box;"><?php esc_html_e( 'Cargando…', 'hwe' ); ?></pre>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'hwe_clear_log' ); ?>
			<input type="hidden" name="action" value="hwe_clear_log">
			<?php submit_button( 'Borrar log actual', 'secondary', 'submit', false, [ 'onclick' => "return confirm('¿Seguro que quieres borrar el log actual?');" ] ); ?>
		</form>

		<hr>

		<h2 class="title"><?php esc_html_e( 'Archivos rotados', 'hwe' ); ?></h2>
		<?php if ( empty( $rotated ) ) : ?>
			<p><?php esc_html_e( 'No hay archivos rotados.', 'hwe' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Archivo', 'hwe' ); ?></th>
					<th><?php esc_html_e( 'Tamaño', 'hwe' ); ?></th>
					<th><?php esc_html_e( 'Última modificación', 'hwe' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'hwe' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rotated as $f ) : ?>
					<tr>
						<td><?php echo esc_html( basename( $f ) ); ?></td>
						<td><?php echo esc_html( size_format( filesize( $f ) ) ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $f ) ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
								<?php wp_nonce_field( 'hwe_delete_log_file' ); ?>
								<input type="hidden" name="action" value="hwe_delete_log_file">
								<input type="hidden" name="filename" value="<?php echo esc_attr( basename( $f ) ); ?>">
								<button type="submit" class="button button-secondary" onclick="return confirm('¿Eliminar <?php echo esc_js( basename( $f ) ); ?>?')"><?php esc_html_e( 'Eliminar', 'hwe' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
				<?php wp_nonce_field( 'hwe_delete_all_rotated_logs' ); ?>
				<input type="hidden" name="action" value="hwe_delete_all_rotated_logs">
				<?php submit_button( 'Eliminar todos los archivos rotados', 'delete', 'submit', false, [ 'onclick' => "return confirm('¿Eliminar TODOS los archivos rotados?');" ] ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * 4) AJAX: últimas N líneas de debug.log
 * ------------------------------------------------------------------------ */

add_action( 'wp_ajax_hwe_get_log', function () {
	check_ajax_referer( 'hwe_get_log', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( '', '', [ 'response' => 403 ] );

	$log = hwe_logmgr_debug_log();
	header( 'Content-Type: text/plain; charset=utf-8' );
	if ( ! file_exists( $log ) || filesize( $log ) === 0 ) {
		echo '— Log vacío —';
		wp_die();
	}
	$lines = max( 1, min( 5000, (int) ( $_POST['lines'] ?? hwe_logmgr_opt( 'view_lines', 200 ) ) ) );
	// phpcs:ignore WordPress.Security.EscapeOutput -- salida plain text, no HTML
	echo hwe_logmgr_tail( $log, $lines );
	wp_die();
} );

/* ---------------------------------------------------------------------------
 * 5) Acciones admin-post
 * ------------------------------------------------------------------------ */

add_action( 'admin_post_hwe_clear_log', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
	check_admin_referer( 'hwe_clear_log' );
	$log = hwe_logmgr_debug_log();
	if ( file_exists( $log ) ) file_put_contents( $log, '' );
	wp_safe_redirect( add_query_arg( [ 'page' => 'hwe-logs', 'cleared' => 1 ], admin_url( 'options-general.php' ) ) );
	exit;
} );

add_action( 'admin_post_hwe_delete_log_file', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
	check_admin_referer( 'hwe_delete_log_file' );

	$name = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
	// Patrón estricto: solo archivos generados por esta rotación.
	if ( ! preg_match( '/^debug-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\.log$/', $name ) ) {
		wp_die( esc_html__( 'Nombre de archivo no válido.', 'hwe' ) );
	}

	$real = realpath( WP_CONTENT_DIR . '/' . $name );
	$base = realpath( WP_CONTENT_DIR );
	// dirname($real) === $base asegura que el archivo está directamente en WP_CONTENT_DIR (sin traversal).
	if ( ! $real || ! $base || dirname( $real ) !== $base ) {
		wp_die( esc_html__( 'Ruta no permitida.', 'hwe' ) );
	}

	@unlink( $real );
	wp_safe_redirect( add_query_arg( [ 'page' => 'hwe-logs', 'deleted' => 1 ], admin_url( 'options-general.php' ) ) );
	exit;
} );

add_action( 'admin_post_hwe_delete_all_rotated_logs', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
	check_admin_referer( 'hwe_delete_all_rotated_logs' );
	foreach ( hwe_logmgr_rotated_files() as $f ) @unlink( $f );
	wp_safe_redirect( add_query_arg( [ 'page' => 'hwe-logs', 'deleted_all' => 1 ], admin_url( 'options-general.php' ) ) );
	exit;
} );

add_action( 'admin_post_hwe_save_logmgr_config', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
	check_admin_referer( 'hwe_save_logmgr_config' );

	$valid_sizes = [ 1048576, 5242880, 10485760, 52428800 ];
	$valid_files = [ 5, 10, 20, 50 ];
	$valid_lines = [ 100, 200, 500, 1000 ];

	$max_size    = (int) ( $_POST['hwe_logmgr_max_size'] ?? 5242880 );
	$retain_days = max( 1, min( 90, (int) ( $_POST['hwe_logmgr_retain_days'] ?? 7 ) ) );
	$max_files   = (int) ( $_POST['hwe_logmgr_max_files'] ?? 10 );
	$view_lines  = (int) ( $_POST['hwe_logmgr_view_lines'] ?? 200 );

	update_option( 'hwe_logmgr_max_size',    in_array( $max_size,   $valid_sizes, true ) ? $max_size   : 5242880 );
	update_option( 'hwe_logmgr_retain_days', $retain_days );
	update_option( 'hwe_logmgr_max_files',   in_array( $max_files,  $valid_files, true ) ? $max_files  : 10 );
	update_option( 'hwe_logmgr_view_lines',  in_array( $view_lines, $valid_lines, true ) ? $view_lines : 200 );

	wp_safe_redirect( add_query_arg( [ 'page' => 'hwe-logs', 'saved' => 1 ], admin_url( 'options-general.php' ) ) );
	exit;
} );
