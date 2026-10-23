<?php
/**
 * Pantallas del menú «Fidelización» (spec §12).
 *
 * Resumen, movimientos filtrables, red de referidos, ajuste manual y lote de códigos. Todas
 * leen con `hwe_loyalty_view`; las que escriben (ajuste y lote) envían a `admin-post.php` con
 * su nonce y la capacidad `hwe_loyalty_manage`, que el manejador vuelve a comprobar.
 *
 * La salida pasa siempre por `esc_html`/`esc_attr`/`esc_url`; el HTML de WooCommerce (precios)
 * por `wp_kses_post`. Ningún texto lleva el nombre de la unidad escrito a mano: sale de
 * `currency_name_plural`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Pages {

	// ------------------------------------------------------------------------
	//  Resumen
	// ------------------------------------------------------------------------

	public static function renderSummary(): void {
		if ( ! self::guard() ) {
			return;
		}

		$days  = isset( $_GET['period'] ) ? absint( wp_unslash( $_GET['period'] ) ) : HWE_Loyalty_Admin_Stats::DEFAULT_DAYS; // phpcs:ignore WordPress.Security.NonceVerification
		$stats = HWE_Loyalty_Admin_Stats::summary( $days );
		$unit  = HWE_Loyalty_Settings::get()['currency_name_plural'];

		self::open( 'Resumen' );

		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf( 'Periodo: últimos %d días. Las cifras salen del libro mayor y de la tabla de referidos.', $stats['days'] ) )
		);

		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		self::metric( 'En circulación', $stats['circulation'], $unit );
		self::metric( 'Emitidos en el periodo', $stats['issued'], $unit );
		self::metric( 'Canjeados en el periodo', $stats['redeemed'], $unit );
		self::metric( 'Caducados en el periodo', $stats['expired'], $unit );
		self::metric( 'Referidos confirmados', $stats['referrals_confirmed'] );
		self::metric( 'Referidos pendientes', $stats['referrals_pending'] );
		echo '</tbody></table>';

		echo '<p>';
		foreach ( array( 30 => '30 días', 90 => '90 días', 365 => '365 días' ) as $value => $label ) {
			printf(
				'<a class="button %s" href="%s">%s</a> ',
				(int) $value === $stats['days'] ? 'button-primary' : '',
				esc_url( HWE_Loyalty_Admin_Menu::url( HWE_Loyalty_Admin_Menu::SUMMARY, array( 'period' => $value ) ) ),
				esc_html( $label )
			);
		}
		echo '</p>';

		echo '<h2>' . esc_html__( 'Mejores referidores', 'hwe' ) . '</h2>';
		if ( array() === $stats['top_referrers'] ) {
			echo '<p><em>' . esc_html__( 'Todavía no hay referidos confirmados.', 'hwe' ) . '</em></p>';
		} else {
			echo '<table class="widefat striped" style="max-width:520px"><thead><tr>'
				. '<th>' . esc_html__( 'Usuario', 'hwe' ) . '</th>'
				. '<th>' . esc_html__( 'Referidos confirmados', 'hwe' ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $stats['top_referrers'] as $row ) {
				$referrerId = (int) ( $row->referrer_id ?? 0 );
				echo '<tr><td>';
				printf(
					'<a href="%s">%s</a>',
					esc_url( HWE_Loyalty_Admin_Users::profileUrl( $referrerId ) ),
					esc_html( HWE_Loyalty_Admin_Users::label( $referrerId ) )
				);
				echo '</td><td>' . esc_html( number_format_i18n( (int) ( $row->total ?? 0 ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<p><a class="button" href="' . esc_url( HWE_Loyalty_Admin_Menu::url( HWE_Loyalty_Admin_Menu::MOVEMENTS ) ) . '">'
			. esc_html__( 'Ver movimientos', 'hwe' ) . '</a> '
			. '<a class="button" href="' . esc_url( HWE_Loyalty_Admin_Menu::url( HWE_Loyalty_Admin_Menu::ADJUST ) ) . '">'
			. esc_html__( 'Ajuste manual', 'hwe' ) . '</a></p>';

		self::close();
	}

	// ------------------------------------------------------------------------
	//  Movimientos
	// ------------------------------------------------------------------------

	public static function renderMovements(): void {
		if ( ! self::guard() ) {
			return;
		}

		$filters = HWE_Loyalty_Admin_Movements::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$page    = HWE_Loyalty_Admin_Movements::query( $filters, HWE_Loyalty_Admin_Movements::PER_PAGE, ( $paged - 1 ) * HWE_Loyalty_Admin_Movements::PER_PAGE );
		$pages   = max( 1, (int) ceil( $page['total'] / HWE_Loyalty_Admin_Movements::PER_PAGE ) );

		self::open( 'Movimientos' );
		self::movementFilters( $filters );

		printf(
			'<p>%s</p>',
			esc_html( sprintf( '%s movimientos.', number_format_i18n( $page['total'] ) ) )
		);

		if ( array() === $page['items'] ) {
			echo '<p><em>' . esc_html__( 'Sin movimientos con estos filtros.', 'hwe' ) . '</em></p>';
		} else {
			self::renderMovementRows( $page['items'] );
		}

		if ( $pages > 1 ) {
			echo '<p>';
			$base = HWE_Loyalty_Admin_Menu::url( HWE_Loyalty_Admin_Menu::MOVEMENTS, self::filterArgs( $filters ) );
			if ( $paged > 1 ) {
				printf( '<a class="button" href="%s">« %s</a> ', esc_url( add_query_arg( 'paged', $paged - 1, $base ) ), esc_html__( 'Anterior', 'hwe' ) );
			}
			printf(
				'<span>%s</span> ',
				esc_html( sprintf( 'Página %s de %s', number_format_i18n( $paged ), number_format_i18n( $pages ) ) )
			);
			if ( $paged < $pages ) {
				printf( '<a class="button" href="%s">%s »</a>', esc_url( add_query_arg( 'paged', $paged + 1, $base ) ), esc_html__( 'Siguiente', 'hwe' ) );
			}
			echo '</p>';
		}

		$export = add_query_arg(
			array_merge( array( 'action' => HWE_Loyalty_Admin_Csv::ACTION ), self::filterArgs( $filters ) ),
			admin_url( 'admin-post.php' )
		);
		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( wp_nonce_url( $export, HWE_Loyalty_Admin_Csv::NONCE ) ),
			esc_html__( 'Exportar CSV', 'hwe' )
		);

		self::close();
	}

	/**
	 * Filas de la tabla. Separado del resto para poder probar el escapado con datos hostiles.
	 *
	 * @param list<object> $rows
	 */
	private static function renderMovementRows( array $rows ): void {
		echo '<table class="widefat striped"><thead><tr>';
		$heads = array(
			'Fecha',
			'Usuario',
			'Tipo',
			'Cantidad',
			'Remanente',
			'Referencia',
			'Nota',
			'Caduca',
		);
		foreach ( $heads as $head ) {
			printf( '<th>%s</th>', esc_html( $head ) );
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$userId = (int) ( $row->user_id ?? 0 );
			$name   = isset( $row->display_name ) && '' !== trim( (string) $row->display_name )
				? (string) $row->display_name
				: HWE_Loyalty_Admin_Users::label( $userId );

			echo '<tr>';
			echo '<td>' . esc_html( self::date( (string) ( $row->created_at ?? '' ) ) ) . '</td>';
			echo '<td>';
			if ( $userId > 0 ) {
				printf(
					'<a href="%s">%s</a>',
					esc_url( HWE_Loyalty_Admin_Users::profileUrl( $userId ) ),
					esc_html( $name )
				);
			} else {
				echo '—';
			}
			echo '</td>';
			echo '<td>' . esc_html( HWE_Loyalty_Admin_Movements::typeLabel( (string) ( $row->type ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( self::signed( (int) ( $row->points ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( null === ( $row->remaining_points ?? null ) ? '—' : number_format_i18n( (int) $row->remaining_points ) ) . '</td>';
			echo '<td>' . esc_html( self::reference( $row ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) ( $row->note ?? '' ) ? (string) $row->note : '—' ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) ( $row->expires_at ?? '' ) ? self::date( (string) $row->expires_at ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// ------------------------------------------------------------------------
	//  Red
	// ------------------------------------------------------------------------

	public static function renderNetwork(): void {
		if ( ! self::guard() ) {
			return;
		}

		$term = isset( $_GET['hwe_user'] ) ? sanitize_text_field( wp_unslash( $_GET['hwe_user'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$user = '' !== $term ? HWE_Loyalty_Admin_Users::resolve( $term ) : null;

		self::open( 'Red de referidos' );

		echo '<form method="get" style="margin:1em 0">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( HWE_Loyalty_Admin_Menu::slug( HWE_Loyalty_Admin_Menu::NETWORK ) ) );
		printf(
			'<input type="search" name="hwe_user" value="%s" placeholder="%s" style="min-width:320px" /> ',
			esc_attr( $term ),
			esc_attr__( 'ID, email o login del referidor', 'hwe' )
		);
		submit_button( __( 'Buscar', 'hwe' ), 'secondary', '', false );
		echo '</form>';

		if ( '' !== $term && null === $user ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No existe ningún usuario con ese id, email o login.', 'hwe' ) . '</p></div>';
			self::close();
			return;
		}

		if ( null === $user ) {
			echo '<p><em>' . esc_html__( 'Busca un usuario para ver su referidor y su red de dos niveles.', 'hwe' ) . '</em></p>';
			self::close();
			return;
		}

		echo '<h2>' . esc_html( HWE_Loyalty_Admin_Users::label( $user ) ) . ' <code>#' . esc_html( (string) $user ) . '</code></h2>';

		$referrerId = \HWE_Loyalty_Referrals::referrerOf( $user, false );
		echo '<p>';
		if ( null === $referrerId ) {
			echo esc_html__( 'Sin referidor.', 'hwe' );
		} else {
			printf(
				'%s <a href="%s">%s</a>',
				esc_html__( 'Referidor:', 'hwe' ),
				esc_url( HWE_Loyalty_Admin_Users::profileUrl( $referrerId ) ),
				esc_html( HWE_Loyalty_Admin_Users::label( $referrerId ) )
			);
		}
		echo '</p>';

		$totals = \HWE_Loyalty_Referrals::networkTotals( $user );
		$unit   = HWE_Loyalty_Settings::get()['currency_name_plural'];
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					'Nivel 1: %s · Nivel 2: %s · Pendientes: %s · Generado: %s %s',
					number_format_i18n( (int) ( $totals['level1'] ?? 0 ) ),
					number_format_i18n( (int) ( $totals['level2'] ?? 0 ) ),
					number_format_i18n( (int) ( $totals['pending'] ?? 0 ) ),
					number_format_i18n( (int) ( $totals['earned'] ?? 0 ) ),
					$unit
				)
			)
		);

		self::networkTable( $user, 1, 'Nivel 1', $term );
		self::networkTable( $user, 2, 'Nivel 2', $term );

		self::close();
	}

	private static function networkTable( int $userId, int $level, string $title, string $term ): void {
		$paged = isset( $_GET[ 'hwe_n' . $level . 'page' ] ) ? max( 1, absint( wp_unslash( $_GET[ 'hwe_n' . $level . 'page' ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$page  = \HWE_Loyalty_Referrals::network( $userId, $level, $paged, 20 );
		$pages = max( 1, (int) ceil( (int) $page['total'] / 20 ) );
		$unit  = HWE_Loyalty_Settings::get()['currency_name_plural'];

		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( array() === $page['items'] ) {
			echo '<p><em>' . esc_html__( 'Sin referidos en este nivel.', 'hwe' ) . '</em></p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Usuario', 'hwe' ) . '</th>'
			. '<th>' . esc_html__( 'Fecha', 'hwe' ) . '</th>'
			. '<th>' . esc_html__( 'Estado', 'hwe' ) . '</th>'
			. '<th>' . esc_html( sprintf( 'Generado (%s)', $unit ) ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $page['items'] as $item ) {
			$name = isset( $item['name'] ) && '' !== trim( (string) $item['name'] )
				? (string) $item['name']
				: __( 'Cuenta eliminada', 'hwe' );
			echo '<tr>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( self::date( (string) ( $item['date'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( self::statusLabel( (string) ( $item['status'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $item['points_generated'] ?? 0 ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $pages > 1 ) {
			echo '<p>';
			$base = HWE_Loyalty_Admin_Menu::url( HWE_Loyalty_Admin_Menu::NETWORK, array( 'hwe_user' => $term ) );
			if ( $paged > 1 ) {
				printf( '<a class="button" href="%s">« %s</a> ', esc_url( add_query_arg( 'hwe_n' . $level . 'page', $paged - 1, $base ) ), esc_html__( 'Anterior', 'hwe' ) );
			}
			printf( '<span>%s</span> ', esc_html( sprintf( 'Página %s de %s', number_format_i18n( $paged ), number_format_i18n( $pages ) ) ) );
			if ( $paged < $pages ) {
				printf( '<a class="button" href="%s">%s »</a>', esc_url( add_query_arg( 'hwe_n' . $level . 'page', $paged + 1, $base ) ), esc_html__( 'Siguiente', 'hwe' ) );
			}
			echo '</p>';
		}
	}

	// ------------------------------------------------------------------------
	//  Ajuste manual
	// ------------------------------------------------------------------------

	public static function renderAdjust(): void {
		if ( ! self::guard() ) {
			return;
		}

		self::open( 'Ajuste manual' );

		if ( ! HWE_Loyalty_Admin_Capabilities::canManage() ) {
			echo '<p><em>' . esc_html__( 'Tu cuenta puede consultar la fidelización, pero no ajustar saldos.', 'hwe' ) . '</em></p>';
			self::close();
			return;
		}

		$unit = HWE_Loyalty_Settings::get()['currency_name_plural'];

		printf(
			'<p class="description">%s</p>',
			esc_html( sprintf( 'El abono o cargo se registra en el libro con tu id y la nota. La nota es obligatoria y un cargo nunca deja el saldo por debajo de cero. La cantidad se expresa en %s.', $unit ) )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:620px">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( HWE_Loyalty_Admin_Adjustments::ACTION ) );
		wp_nonce_field( HWE_Loyalty_Admin_Adjustments::NONCE, HWE_Loyalty_Admin_Adjustments::FIELD );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="hwe_loyalty_user">' . esc_html__( 'Usuario', 'hwe' ) . '</label></th><td>'
			. '<input type="text" class="regular-text" id="hwe_loyalty_user" name="hwe_loyalty_user" value="" placeholder="ID, email o login" required />'
			. '</td></tr>';
		echo '<tr><th scope="row"><label for="hwe_loyalty_direction">' . esc_html__( 'Movimiento', 'hwe' ) . '</label></th><td>'
			. '<select id="hwe_loyalty_direction" name="hwe_loyalty_direction">'
			. '<option value="credit">' . esc_html__( 'Abonar', 'hwe' ) . '</option>'
			. '<option value="debit">' . esc_html__( 'Cargar', 'hwe' ) . '</option>'
			. '</select></td></tr>';
		echo '<tr><th scope="row"><label for="hwe_loyalty_points">' . esc_html__( 'Cantidad', 'hwe' ) . '</label></th><td>'
			. '<input type="number" min="1" step="1" class="small-text" id="hwe_loyalty_points" name="hwe_loyalty_points" value="" required />'
			. '</td></tr>';
		echo '<tr><th scope="row"><label for="hwe_loyalty_note">' . esc_html__( 'Nota', 'hwe' ) . '</label></th><td>'
			. '<textarea class="large-text" rows="3" id="hwe_loyalty_note" name="hwe_loyalty_note" maxlength="500" required></textarea>'
			. '<p class="description">' . esc_html__( 'Queda en el movimiento y explica el motivo a quien audite el saldo.', 'hwe' ) . '</p>'
			. '</td></tr>';
		echo '</tbody></table>';

		printf(
			'<input type="hidden" name="hwe_loyalty_key" value="%s" />',
			esc_attr( self::newKey() )
		);
		submit_button( __( 'Aplicar ajuste', 'hwe' ) );
		echo '</form>';

		self::close();
	}

	// ------------------------------------------------------------------------
	//  Códigos por lotes
	// ------------------------------------------------------------------------

	public static function renderCodes(): void {
		if ( ! self::guard() ) {
			return;
		}

		self::open( 'Códigos de referido' );

		$remaining = HWE_Loyalty_Admin_Codes::remaining();
		printf(
			'<p>%s</p>',
			esc_html( sprintf( '%s participantes sin código.', number_format_i18n( $remaining ) ) )
		);

		if ( ! HWE_Loyalty_Settings::referralsActive() ) {
			echo '<p><em>' . esc_html__( 'El programa de referidos está apagado: no se asignan códigos.', 'hwe' ) . '</em></p>';
			self::close();
			return;
		}

		if ( ! HWE_Loyalty_Admin_Capabilities::canManage() ) {
			echo '<p><em>' . esc_html__( 'Tu cuenta puede consultar los códigos, pero no asignarlos.', 'hwe' ) . '</em></p>';
			self::close();
			return;
		}

		echo '<p class="description">' . esc_html__( 'Asigna un código nuevo solo a quien no tiene. El lote es acotado: si se corta, vuelve a pulsar el botón y continúa donde quedó, sin duplicar.', 'hwe' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( HWE_Loyalty_Admin_Codes::ACTION ) );
		wp_nonce_field( HWE_Loyalty_Admin_Codes::NONCE, HWE_Loyalty_Admin_Codes::FIELD );
		printf(
			'<label for="hwe_loyalty_batch">%s</label> <input type="number" min="1" max="%d" step="1" id="hwe_loyalty_batch" name="hwe_loyalty_batch" value="%d" class="small-text" /> ',
			esc_html__( 'Tamaño del lote', 'hwe' ),
			HWE_Loyalty_Admin_Codes::MAX_BATCH,
			HWE_Loyalty_Admin_Codes::DEFAULT_BATCH
		);
		submit_button( __( 'Asignar lote', 'hwe' ), 'primary', '', false );
		echo '</form>';

		self::close();
	}

	// ------------------------------------------------------------------------
	//  Piezas compartidas
	// ------------------------------------------------------------------------

	/** Guarda de lectura. Devuelve `false` (y muere con 403) sin `hwe_loyalty_view`. */
	private static function guard(): bool {
		if ( HWE_Loyalty_Admin_Capabilities::canView() ) {
			return true;
		}

		wp_die( 'No autorizado.', 403 );
		return false;
	}

	private static function open( string $title ): void {
		echo '<div class="wrap hwe-loyalty-admin"><h1>' . esc_html__( 'Fidelización', 'hwe' ) . '</h1>';
		echo '<h2>' . esc_html( $title ) . '</h2>';
		HWE_Loyalty_Admin::renderNotice( HWE_Loyalty_Admin::pullFlash() );

		if ( ! HWE_Loyalty_Settings::pointsActive() ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'El programa de fidelización está apagado. Estas pantallas siguen disponibles para auditar el histórico, pero la tienda no abona ni canjea saldo automáticamente.', 'hwe' )
				. '</p></div>';
		}
	}

	private static function close(): void {
		echo '</div>';
	}

	/** @param array{user_id:int,type:string,from:string,to:string,order_id:int} $filters */
	private static function movementFilters( array $filters ): void {
		echo '<form method="get" style="margin:1em 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( HWE_Loyalty_Admin_Menu::slug( HWE_Loyalty_Admin_Menu::MOVEMENTS ) ) );

		$term = '';
		if ( $filters['user_id'] > 0 || -1 === $filters['user_id'] ) {
			$term = -1 === $filters['user_id'] ? '#inexistente' : (string) $filters['user_id'];
		}
		printf(
			'<input type="search" name="hwe_user" value="%s" placeholder="%s" style="min-width:220px" />',
			esc_attr( $term ),
			esc_attr__( 'ID, email o login', 'hwe' )
		);

		echo '<select name="hwe_type"><option value="">' . esc_html__( 'Todos los tipos', 'hwe' ) . '</option>';
		foreach ( \HWE_Loyalty_EntryTypes::all() as $type => $side ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $type ),
				selected( $filters['type'], $type, false ),
				esc_html( HWE_Loyalty_Admin_Movements::typeLabel( $type ) )
			);
		}
		echo '</select>';

		printf(
			'<label>%s <input type="date" name="hwe_from" value="%s" /></label>',
			esc_html__( 'Desde', 'hwe' ),
			esc_attr( $filters['from'] )
		);
		printf(
			'<label>%s <input type="date" name="hwe_to" value="%s" /></label>',
			esc_html__( 'Hasta', 'hwe' ),
			esc_attr( $filters['to'] )
		);
		printf(
			'<input type="number" min="1" step="1" name="hwe_order" value="%s" placeholder="%s" class="small-text" />',
			esc_attr( $filters['order_id'] > 0 ? (string) $filters['order_id'] : '' ),
			esc_attr__( 'Pedido', 'hwe' )
		);

		submit_button( __( 'Filtrar', 'hwe' ), 'secondary', '', false );
		echo '</form>';
	}

	/** @param array{user_id:int,type:string,from:string,to:string,order_id:int} $filters @return array<string,mixed> */
	private static function filterArgs( array $filters ): array {
		return array_filter(
			array(
				'hwe_user'  => 0 !== $filters['user_id'] ? (string) $filters['user_id'] : null,
				'hwe_type'  => '' !== $filters['type'] ? $filters['type'] : null,
				'hwe_from'  => '' !== $filters['from'] ? $filters['from'] : null,
				'hwe_to'    => '' !== $filters['to'] ? $filters['to'] : null,
				'hwe_order' => $filters['order_id'] > 0 ? (string) $filters['order_id'] : null,
			)
		);
	}

	private static function metric( string $label, int $value, string $unit = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><strong>'
			. esc_html( number_format_i18n( $value ) );
		if ( '' !== $unit ) {
			echo ' ' . esc_html( $unit );
		}
		echo '</strong></td></tr>';
	}

	private static function signed( int $points ): string {
		return ( $points > 0 ? '+' : '' ) . number_format_i18n( $points );
	}

	private static function reference( object $row ): string {
		$type = (string) ( $row->ref_type ?? '' );
		$id   = (string) ( $row->ref_id ?? '' );
		if ( '' === $type || '' === $id ) {
			return '—';
		}

		return $type . ' #' . $id;
	}

	private static function statusLabel( string $status ): string {
		return match ( $status ) {
			\HWE_Loyalty_Referrals::STATUS_CONFIRMED => 'Confirmado',
			\HWE_Loyalty_Referrals::STATUS_PENDING   => 'Pendiente',
			default                                  => $status,
		};
	}

	private static function date( string $utc ): string {
		if ( '' === $utc ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );
		return false === $timestamp ? $utc : wp_date( 'Y-m-d H:i', $timestamp );
	}

	private static function newKey(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return uniqid( 'loyalty', true );
		}
	}
}
