<?php
/**
 * Pantalla de wp-admin («Ajustes → Consentimientos de cookies»), exportación CSV y el historial en el perfil de usuario.
 *
 * Es un trait de `HWE_Consent_Log` (hwe-consent-log.php): el plugin es una sola clase con
 * estado estático y se parte por responsabilidad para que cada archivo se lea de una vez.
 * Los métodos usan `self::` y las constantes de la clase que lo incluye.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait HWE_Consent_Log_Admin {
	private static function decisionLabel( $row ): string {
		if ( 'declined' === $row->decision ) {
			return 'Solo necesarias';
		}
		$granted = array();
		if ( (int) $row->analytics ) {
			$granted[] = 'analítica';
		}
		if ( (int) $row->advertising ) {
			$granted[] = 'publicidad';
		}
		return 'Aceptó' . ( $granted ? ' (' . implode( ' + ', $granted ) . ')' : '' );
	}

	/**
	 * Filas de la cuenta y las anónimas de sus mismos comprobantes (lo que
	 * decidió en ese dispositivo antes de iniciar sesión).
	 *
	 * @return object[]
	 */
	private static function historyForUser( int $userId, int $limit ): array {
		global $wpdb;
		$log = self::logTable();
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$log} WHERE user_id = %d OR (user_id IS NULL AND receipt IN (SELECT receipt FROM (SELECT DISTINCT receipt FROM {$log} WHERE user_id = %d) r)) ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$userId,
				$userId,
				$limit
			)
		);
	}

	/** Ajustes → «Consentimientos de cookies». */
	public static function registerAdminPage(): void {
		add_options_page(
			'Consentimientos de cookies',
			'Consentimientos de cookies',
			'manage_options',
			self::ADMIN_SLUG,
			array( self::class, 'renderAdminPage' )
		);
	}

	/**
	 * URL de la pantalla. Cuelga de Ajustes (`options-general.php`): una página
	 * de Ajustes NO se abre por `admin.php?page=`, así que ningún enlace se
	 * construye a mano.
	 */
	public static function pageUrl(): string {
		return admin_url( 'options-general.php?page=' . self::ADMIN_SLUG );
	}

	/**
	 * Filtro de búsqueda: comprobante, email o id de usuario. `$col` antepone
	 * el alias de la tabla principal (la subconsulta lleva el suyo).
	 *
	 * @return array{0:string,1:array} Cláusula WHERE y sus argumentos.
	 */
	private static function searchClause( string $search, string $col = '' ): array {
		$search = trim( $search );
		if ( '' === $search ) {
			return array( '1=1', array() );
		}
		if ( preg_match( self::RECEIPT_PATTERN, strtolower( $search ) ) ) {
			return array( "{$col}receipt = %s", array( strtolower( $search ) ) );
		}
		$user = is_email( $search ) ? get_user_by( 'email', $search ) : ( ctype_digit( $search ) ? get_userdata( (int) $search ) : false );
		if ( ! $user ) {
			return array( '1=0', array() );
		}
		$log = self::logTable();
		return array(
			"({$col}user_id = %d OR ({$col}user_id IS NULL AND {$col}receipt IN (SELECT receipt FROM (SELECT DISTINCT receipt FROM {$log} WHERE user_id = %d) r)))",
			array( (int) $user->ID, (int) $user->ID ),
		);
	}

	/** @return object[] */
	private static function query( string $search, int $limit, int $offset ): array {
		global $wpdb;
		[ $where, $args ] = self::searchClause( $search, 'l.' );
		$log              = self::logTable();
		$texts            = self::textsTable();
		$sql              = "SELECT l.*, t.text FROM {$log} l LEFT JOIN {$texts} t ON t.hash = l.text_hash WHERE {$where} ORDER BY l.id DESC LIMIT %d OFFSET %d";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function count( string $search ): int {
		global $wpdb;
		[ $where, $args ] = self::searchClause( $search );
		$log              = self::logTable();
		$sql              = "SELECT COUNT(*) FROM {$log} WHERE {$where}";
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function userLabel( $row ): string {
		if ( null === $row->user_id ) {
			return 'Anónimo';
		}
		$user = get_userdata( (int) $row->user_id );
		return $user ? $user->user_email : '#' . (int) $row->user_id;
	}

	private static function formatDate( string $utc ): string {
		return wp_date( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) );
	}

	public static function renderAdminPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$total  = self::count( $search );
		$rows   = self::query( $search, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$years  = self::retentionYears();
		$days   = HWE_Consent_Stats::days();
		// URL de ESTA vista (búsqueda, página y periodo de la medición): cada
		// enlace cambia solo lo suyo y conserva lo demás.
		$current = add_query_arg(
			array_filter(
				array(
					's'                          => '' !== $search ? $search : null,
					'paged'                      => $paged > 1 ? $paged : null,
					HWE_Consent_Stats::QUERY_ARG => isset( $_GET[ HWE_Consent_Stats::QUERY_ARG ] ) ? $days : null, // phpcs:ignore WordPress.Security.NonceVerification
				)
			),
			self::pageUrl()
		);

		echo '<div class="wrap hwe-consent-admin"><h1>Consentimientos de cookies</h1>';
		self::renderStyles();

		echo '<h2>Respuesta al banner <span class="hwe-consent-tag">medición anónima</span></h2>';
		echo '<p class="description">De las sesiones que vieron el banner de primera visita, cuántas aceptaron, cuántas eligieron solo las necesarias y cuántas no respondieron. Totales por sesión, sin IP ni cookies y sin robots: no identifica a nadie ni sirve como prueba.</p>';
		echo HWE_Consent_Stats::render( $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya escapado al construirlo.

		echo '<h2>Registro de decisiones <span class="hwe-consent-tag">log</span></h2>';
		printf(
			'<p class="description">Log de cada decisión tomada en el banner de cookies: qué se eligió, cuándo y con qué texto. Es la evidencia legal del consentimiento. IP truncada. Conservación: %s (Control Center → Integraciones).</p>',
			esc_html( 0 === $years ? 'sin borrado automático' : sprintf( '%d años desde la última decisión de cada comprobante', $years ) )
		);

		echo '<form method="get" style="margin:1em 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::ADMIN_SLUG ) );
		if ( isset( $_GET[ HWE_Consent_Stats::QUERY_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<input type="hidden" name="%s" value="%d" />', esc_attr( HWE_Consent_Stats::QUERY_ARG ), (int) $days );
		}
		printf(
			'<input type="search" name="s" value="%s" placeholder="Comprobante, email o ID de usuario" style="min-width:320px" /> ',
			esc_attr( $search )
		);
		submit_button( 'Buscar', 'secondary', '', false );
		printf(
			' <a class="button" href="%s">Exportar CSV</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg( array( 'action' => self::EXPORT_ACTION, 's' => $search ), admin_url( 'admin-post.php' ) ),
					self::EXPORT_ACTION
				)
			)
		);
		echo '</form>';

		printf( '<p>%s registros.</p>', esc_html( number_format_i18n( $total ) ) );
		self::renderRows( $rows, true );

		if ( $pages > 1 ) {
			$link = static fn( int $page ): string => esc_url( add_query_arg( 'paged', $page, $current ) );
			echo '<p>';
			if ( $paged > 1 ) {
				printf( '<a class="button" href="%s">« Anterior</a> ', $link( $paged - 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			printf( 'Página %s de %s ', esc_html( number_format_i18n( $paged ) ), esc_html( number_format_i18n( $pages ) ) );
			if ( $paged < $pages ) {
				printf( '<a class="button" href="%s">Siguiente »</a>', $link( $paged + 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/** Estilos de la pantalla: no carga el CSS de Control Center, que es de su página. */
	private static function renderStyles(): void {
		echo '<style>
			.hwe-consent-admin h2 { margin-top: 1.6em; }
			.hwe-consent-tag { margin-left: 6px; padding: 1px 8px; border-radius: 10px; background: #f0f0f1; color: #50575e; font-size: 12px; font-weight: 400; vertical-align: middle; }
			.hwe-consent-periods { margin: 8px 0; color: #646970; }
			.hwe-consent-figures { display: flex; flex-wrap: wrap; gap: 6px 22px; margin: 0 0 6px; }
			.hwe-consent-figures li { margin: 0; color: #646970; }
			.hwe-consent-figures strong { color: #1d2327; font-variant-numeric: tabular-nums; }
			.hwe-consent-percent { font-variant-numeric: tabular-nums; }
			.hwe-consent-error { margin: 0 0 6px; color: #b45309; }
			.hwe-consent-empty { margin: 0 0 6px; color: #646970; }
		</style>';
	}

	/**
	 * La generación más alta del registro, que es la vigente en el frontend:
	 * cada decisión nueva llega con la suya. Null sin ninguna fila que la lleve.
	 */
	private static function currentGeneration(): ?int {
		static $current = false;
		if ( false === $current ) {
			global $wpdb;
			$log     = self::logTable();
			$value   = $wpdb->get_var( "SELECT MAX(generation) FROM {$log}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$current = null === $value ? null : (int) $value;
		}
		return $current;
	}

	/** «4», «3 (retirada por un reinicio)» o «—» si la fila es anterior a la columna. */
	private static function generationLabel( $row ): string {
		if ( ! isset( $row->generation ) || null === $row->generation ) {
			return '—';
		}
		$gen     = (int) $row->generation;
		$current = self::currentGeneration();
		return null !== $current && $gen < $current ? $gen . ' (anterior: ya no vale)' : (string) $gen;
	}

	/** @param object[] $rows */
	private static function renderRows( array $rows, bool $withUser ): void {
		if ( empty( $rows ) ) {
			echo '<p><em>Sin registros.</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		$heads = array( 'Fecha', 'Acción', 'Decisión', 'Generación', 'Servicios pedidos' );
		if ( $withUser ) {
			$heads[] = 'Usuario';
		}
		array_push( $heads, 'Comprobante', 'IP (truncada)', 'Idioma / ruta', 'Texto y política' );
		foreach ( $heads as $head ) {
			printf( '<th>%s</th>', esc_html( $head ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( self::formatDate( $row->created_at ) ) );
			printf( '<td>%s</td>', esc_html( self::ACTION_LABELS[ $row->action ] ?? $row->action ) );
			printf( '<td>%s</td>', esc_html( self::decisionLabel( $row ) ) );
			printf( '<td>%s</td>', esc_html( self::generationLabel( $row ) ) );
			printf( '<td>%s</td>', esc_html( '' === $row->services ? '—' : $row->services ) );
			if ( $withUser ) {
				printf( '<td>%s</td>', esc_html( self::userLabel( $row ) ) );
			}
			printf( '<td><code>%s</code></td>', esc_html( $row->receipt ) );
			printf( '<td>%s</td>', esc_html( (string) $row->ip ) );
			printf( '<td>%s %s</td>', esc_html( $row->locale ), esc_html( (string) $row->path ) );
			$text = property_exists( $row, 'text' ) ? (string) $row->text : '';
			printf(
				'<td><details><summary>%s</summary><p>%s</p><p><small>Política de cookies: %s · Banner: %s · %s</small></p></details></td>',
				esc_html( substr( $row->text_hash, 0, 10 ) ),
				esc_html( $text ),
				esc_html( $row->policy_version ? $row->policy_version : '—' ),
				esc_html( $row->variant ),
				esc_html( (string) $row->user_agent )
			);
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Celda de CSV inerte: el user-agent y la ruta los escribe quien visita el
	 * sitio, y una celda que empiece por `=`, `+`, `-`, `@` (o tabulador /
	 * retorno) Excel la ejecuta como fórmula al abrir el archivo.
	 */
	private static function csvCell( $value ): string {
		$value = (string) $value;
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	public static function exportCsv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.', 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consentimientos-cookies-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM: Excel abre el UTF-8 sin romper las tildes.
		fputcsv( $out, array( 'fecha_utc', 'accion', 'decision', 'analitica', 'publicidad', 'servicios', 'usuario', 'comprobante', 'ip_truncada', 'user_agent', 'idioma', 'ruta', 'variante', 'politica_version', 'texto_hash', 'texto', 'hora_navegador_utc', 'generacion' ) );
		$offset = 0;
		do {
			$rows = self::query( $search, 1000, $offset );
			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array_map(
						static fn( $value ): string => self::csvCell( $value ),
						array(
							$row->created_at,
							$row->action,
							$row->decision,
							$row->analytics,
							$row->advertising,
							$row->services,
							self::userLabel( $row ),
							$row->receipt,
							$row->ip,
							$row->user_agent,
							$row->locale,
							$row->path,
							$row->variant,
							$row->policy_version,
							$row->text_hash,
							$row->text ?? '',
							$row->client_at,
							$row->generation ?? '',
						)
					)
				);
			}
			$offset += 1000;
		} while ( count( $rows ) === 1000 );
		fclose( $out );
		exit;
	}

	public static function renderProfileHistory( WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		echo '<h2>Consentimiento de cookies</h2>';
		echo '<p class="description">Decisiones de esta persona en el banner de cookies, incluidas las que tomó sin sesión en un dispositivo que luego vinculó a la cuenta. La más reciente de cada comprobante es la vigente en ese dispositivo, salvo que sea de una generación anterior: un reinicio del consentimiento la retiró y el banner volvió a preguntar.</p>';
		self::renderRows( self::historyForUser( (int) $user->ID, 50 ), false );
	}
}
