<?php
/**
 * Historial de membresías en wp-admin (spec §11.1): filtrable por usuario, acción, nivel y fecha.
 *
 * La fuente es la tabla `hwe_memberships_events` (el historial solo crece) unida a `wp_users`
 * únicamente para el nombre visible. Los filtros y el total se resuelven en SQL con
 * `LIMIT/OFFSET`: ningún filtro carga el histórico entero. Los valores interpolados son ids
 * numéricos, tipos de una lista blanca y fechas ya validadas, nunca texto libre.
 *
 * El nivel se muestra por su nombre resuelto desde el CPT; un nivel borrado se pinta como
 * «#id». La nota de una acción de administración vive en los datos estructurados del evento.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_History_Screen {

	/** Filas por página. */
	public const PER_PAGE = 30;

	/** Tipos que expone el filtro (el resto del historial no se genera hoy). */
	private const TYPES = array(
		HWE_Memberships_Events::ACTIVATED,
		HWE_Memberships_Events::RENEWED,
		HWE_Memberships_Events::UPGRADED,
		HWE_Memberships_Events::DEFERRED,
		HWE_Memberships_Events::EXPIRED,
		HWE_Memberships_Events::EXPIRING,
		HWE_Memberships_Events::FROZEN,
		HWE_Memberships_Events::UNFROZEN,
		HWE_Memberships_Events::GRANTED_BY_ADMIN,
		HWE_Memberships_Events::REMOVED_BY_ADMIN,
		HWE_Memberships_Events::REVOKED,
		HWE_Memberships_Events::REFERRAL_GIFT_GRANTED,
		HWE_Memberships_Events::REFERRAL_GIFT_LIMIT,
		HWE_Memberships_Events::POINTS_SKIPPED,
	);

	// ------------------------------------------------------------------------
	//  Consulta
	// ------------------------------------------------------------------------

	/**
	 * Filtros saneados de la petición. Un usuario que no existe se representa con `-1` para que
	 * la consulta no devuelva nada en vez de ignorar el filtro en silencio.
	 *
	 * @param array<string,mixed> $source Normalmente `$_GET`.
	 * @return array{user_id:int,type:string,level:int,from:string,to:string,paged:int}
	 */
	public static function filters( array $source ): array {
		$scalar = static function ( string $key ) use ( $source ): string {
			return isset( $source[ $key ] ) && is_scalar( $source[ $key ] )
				? (string) wp_unslash( $source[ $key ] )
				: '';
		};

		$filters = array(
			'user_id' => 0,
			'type'    => '',
			'level'   => 0,
			'from'    => '',
			'to'      => '',
			'paged'   => 1,
		);

		$term = sanitize_text_field( $scalar( 'hwe_user' ) );
		if ( '' !== $term ) {
			$userId             = self::resolveUser( $term );
			$filters['user_id'] = null === $userId ? -1 : (int) $userId;
		}

		$type = sanitize_key( $scalar( 'hwe_type' ) );
		if ( '' !== $type && array_key_exists( $type, self::types() ) ) {
			$filters['type'] = $type;
		}

		$filters['level'] = absint( $scalar( 'hwe_level' ) );

		foreach ( array( 'from', 'to' ) as $key ) {
			$value = sanitize_text_field( $scalar( 'hwe_' . $key ) );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$filters[ $key ] = $value;
			}
		}

		$filters['paged'] = max( 1, (int) $scalar( 'paged' ) );

		return $filters;
	}

	/**
	 * Página de eventos, del más nuevo al más antiguo.
	 *
	 * @param array{user_id:int,type:string,level:int,from:string,to:string,paged:int} $filters
	 * @return list<object>
	 */
	public static function items( array $filters, int $limit = self::PER_PAGE, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 1000, $limit ) );
		$offset = max( 0, $offset );
		$events = HWE_Memberships_Schema::eventsTable();
		$users  = $wpdb->prefix . 'users';
		$where  = self::where( $filters );

		$sql = "SELECT e.*, u.display_name FROM {$events} e"
			. " LEFT JOIN {$users} u ON u.ID = e.user_id"
			. " WHERE {$where} ORDER BY e.created_at DESC, e.id DESC LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array{user_id:int,type:string,level:int,from:string,to:string,paged:int} $filters
	 */
	public static function count( array $filters ): int {
		global $wpdb;

		$events = HWE_Memberships_Schema::eventsTable();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events} e WHERE " . self::where( $filters ) );
	}

	/**
	 * @param array{user_id:int,type:string,level:int,from:string,to:string,paged:int} $filters
	 * @return array{items:list<object>,total:int}
	 */
	public static function query( array $filters, int $limit = self::PER_PAGE, int $offset = 0 ): array {
		return array(
			'items' => self::items( $filters, $limit, $offset ),
			'total' => self::count( $filters ),
		);
	}

	/**
	 * WHERE compartido por el listado y el conteo.
	 *
	 * @param array{user_id:int,type:string,level:int,from:string,to:string,paged:int} $filters
	 */
	private static function where( array $filters ): string {
		$parts = array( '1=1' );

		if ( 0 !== (int) $filters['user_id'] ) {
			$parts[] = 'e.user_id = ' . (int) $filters['user_id'];
		}
		if ( '' !== (string) $filters['type'] ) {
			$parts[] = "e.type = '" . (string) $filters['type'] . "'";
		}
		if ( (int) $filters['level'] > 0 ) {
			$level   = (int) $filters['level'];
			$parts[] = "(e.level_from = {$level} OR e.level_to = {$level})";
		}
		if ( '' !== (string) $filters['from'] ) {
			$parts[] = "e.created_at >= '" . (string) $filters['from'] . " 00:00:00'";
		}
		if ( '' !== (string) $filters['to'] ) {
			$parts[] = "e.created_at <= '" . (string) $filters['to'] . " 23:59:59'";
		}

		return implode( ' AND ', $parts );
	}

	/**
	 * Resuelve el término de búsqueda a un id: id numérico, email o login. `null` si no existe.
	 * No busca por nombre visible: dos personas pueden llamarse igual y el id sería ambiguo.
	 */
	public static function resolveUser( string $term ): ?int {
		$term = trim( $term );
		if ( '' === $term ) {
			return null;
		}

		if ( ctype_digit( $term ) ) {
			$user = function_exists( 'get_userdata' ) ? get_userdata( (int) $term ) : false;
			return is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : null;
		}

		if ( ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		foreach ( array( 'email', 'login', 'slug' ) as $field ) {
			$user = get_user_by( $field, $term );
			if ( is_object( $user ) && isset( $user->ID ) ) {
				return (int) $user->ID;
			}
		}

		return null;
	}

	// ------------------------------------------------------------------------
	//  Presentación
	// ------------------------------------------------------------------------

	/** Pantalla del historial. */
	public static function render(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			wp_die( esc_html__( 'No tienes permiso para ver el historial de membresías.', 'hwe' ) );
			return;
		}

		$filters = self::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$offset  = ( (int) $filters['paged'] - 1 ) * self::PER_PAGE;
		$result  = self::query( $filters, self::PER_PAGE, $offset );

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Historial de membresías', 'hwe' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		HWE_Memberships_Admin::renderNotice( HWE_Memberships_Admin::pullFlash() );

		self::renderFilters( $filters );

		if ( array() === $result['items'] ) {
			echo '<p><em>' . esc_html__( 'No hay eventos que cumplan los filtros.', 'hwe' ) . '</em></p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Fecha', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Socio', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Acción', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Nivel', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actor', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Nota', 'hwe' ) . '</th>';
		echo '</tr></thead><tbody>';

		self::renderRows( $result['items'] );

		echo '</tbody></table>';

		$pages = (int) ceil( (int) $result['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			$urlArgs = array_filter( array(
				'hwe_user'  => (string) ( $filters['user_id'] > 0 ? $filters['user_id'] : '' ),
				'hwe_type'  => (string) $filters['type'],
				'hwe_level' => $filters['level'] > 0 ? (string) $filters['level'] : '',
				'hwe_from'  => (string) $filters['from'],
				'hwe_to'    => (string) $filters['to'],
			), static fn ( string $value ): bool => '' !== $value );
			echo '<p class="tablenav-pages">';
			if ( (int) $filters['paged'] > 1 ) {
				echo '<a class="button" href="' . esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::HISTORY, $urlArgs + array( 'paged' => (int) $filters['paged'] - 1 ) ) ) . '">'
					. esc_html__( 'Anterior', 'hwe' ) . '</a> ';
			}
			printf(
				'<span class="displaying-num">%s</span> ',
				esc_html( sprintf(
					/* translators: 1: página actual, 2: total de páginas, 3: total de eventos. */
					__( 'Página %1$d de %2$d · %3$d eventos', 'hwe' ),
					(int) $filters['paged'],
					$pages,
					(int) $result['total']
				) )
			);
			if ( (int) $filters['paged'] < $pages ) {
				echo '<a class="button" href="' . esc_url( HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::HISTORY, $urlArgs + array( 'paged' => (int) $filters['paged'] + 1 ) ) ) . '">'
					. esc_html__( 'Siguiente', 'hwe' ) . '</a>';
			}
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Cuerpo de la tabla. Separado del render para probar el escapado sin montar la pantalla.
	 *
	 * @param list<object> $rows
	 */
	private static function renderRows( array $rows ): void {
		foreach ( $rows as $row ) {
			$userId = (int) ( $row->user_id ?? 0 );
			$name   = isset( $row->display_name ) ? (string) $row->display_name : '';

			echo '<tr>';
			echo '<td>' . esc_html( self::formatDate( (string) ( $row->created_at ?? '' ) ) ) . '</td>';
			echo '<td>';
			if ( $userId > 0 ) {
				printf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'user-edit.php?user_id=' . $userId ) ),
					esc_html( '' !== $name ? $name : '#' . $userId )
				);
			} else {
				echo '—';
			}
			echo '</td>';
			echo '<td>' . esc_html( self::typeLabel( (string) ( $row->type ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( self::levelTransition( (int) ( $row->level_from ?? 0 ), (int) ( $row->level_to ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( self::actorLabel( (string) ( $row->actor ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( self::noteOf( $row ) ) . '</td>';
			echo '</tr>';
		}
	}

	/**
	 * Formulario de filtros.
	 *
	 * @param array{user_id:int,type:string,level:int,from:string,to:string,paged:int} $filters
	 */
	public static function renderFilters( array $filters ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( HWE_Memberships_Admin_Menu::slug( HWE_Memberships_Admin_Menu::HISTORY ) ) . '">';

		printf(
			'<input type="text" name="hwe_user" value="%s" placeholder="%s" class="regular-text" /> ',
			esc_attr( $filters['user_id'] > 0 ? (string) $filters['user_id'] : '' ),
			esc_attr__( 'ID, email o usuario', 'hwe' )
		);

		echo '<select name="hwe_type">';
		echo '<option value="">' . esc_html__( 'Cualquier acción', 'hwe' ) . '</option>';
		foreach ( self::types() as $type => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $type ),
				$type === (string) $filters['type'] ? ' selected="selected"' : '',
				esc_html( $label )
			);
		}
		echo '</select> ';

		echo '<select name="hwe_level">';
		echo '<option value="0">' . esc_html__( 'Cualquier nivel', 'hwe' ) . '</option>';
		foreach ( HWE_Memberships_Levels::all( true ) as $level ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) (int) $level['id'] ),
				(int) $level['id'] === (int) $filters['level'] ? ' selected="selected"' : '',
				esc_html( (string) $level['name'] )
			);
		}
		echo '</select> ';

		printf(
			'<label>%s <input type="date" name="hwe_from" value="%s" /></label> ',
			esc_html__( 'Desde', 'hwe' ),
			esc_attr( (string) $filters['from'] )
		);
		printf(
			'<label>%s <input type="date" name="hwe_to" value="%s" /></label> ',
			esc_html__( 'Hasta', 'hwe' ),
			esc_attr( (string) $filters['to'] )
		);

		echo '<button type="submit" class="button">' . esc_html__( 'Filtrar', 'hwe' ) . '</button>';
		echo '</form>';
	}

	// ------------------------------------------------------------------------
	//  Etiquetas
	// ------------------------------------------------------------------------

	/** @return array<string,string> Tipos de evento y su etiqueta. */
	public static function types(): array {
		$types = array();
		foreach ( self::TYPES as $type ) {
			$types[ $type ] = self::typeLabel( $type );
		}

		return $types;
	}

	/** Etiqueta de UI de un tipo de evento. */
	public static function typeLabel( string $type ): string {
		return match ( $type ) {
			HWE_Memberships_Events::ACTIVATED             => __( 'Alta', 'hwe' ),
			HWE_Memberships_Events::RENEWED               => __( 'Renovación', 'hwe' ),
			HWE_Memberships_Events::UPGRADED              => __( 'Mejora de nivel', 'hwe' ),
			HWE_Memberships_Events::DEFERRED              => __( 'Programada', 'hwe' ),
			HWE_Memberships_Events::EXPIRED               => __( 'Caducidad', 'hwe' ),
			HWE_Memberships_Events::EXPIRING              => __( 'Aviso de caducidad', 'hwe' ),
			HWE_Memberships_Events::FROZEN                => __( 'Congelación', 'hwe' ),
			HWE_Memberships_Events::UNFROZEN              => __( 'Descongelación', 'hwe' ),
			HWE_Memberships_Events::GRANTED_BY_ADMIN      => __( 'Concedida por administración', 'hwe' ),
			HWE_Memberships_Events::REMOVED_BY_ADMIN      => __( 'Retirada por administración', 'hwe' ),
			HWE_Memberships_Events::REVOKED               => __( 'Revocada', 'hwe' ),
			HWE_Memberships_Events::REFERRAL_GIFT_GRANTED => __( 'Regalo de referido', 'hwe' ),
			HWE_Memberships_Events::REFERRAL_GIFT_LIMIT   => __( 'Tope de regalos alcanzado', 'hwe' ),
			HWE_Memberships_Events::POINTS_SKIPPED        => __( 'Puntos omitidos', 'hwe' ),
			default                                       => $type,
		};
	}

	/** Nombre del nivel; `#id` si ya no existe. */
	public static function levelName( int $levelId ): string {
		if ( $levelId <= 0 ) {
			return '';
		}

		$level = HWE_Memberships_Levels::get( $levelId );

		return null === $level ? '#' . $levelId : (string) $level['name'];
	}

	private static function levelTransition( int $from, int $to ): string {
		$fromName = self::levelName( $from );
		$toName   = self::levelName( $to );

		if ( '' === $fromName && '' === $toName ) {
			return '—';
		}
		if ( '' === $fromName ) {
			return '→ ' . $toName;
		}
		if ( '' === $toName || $from === $to ) {
			return $fromName;
		}

		return $fromName . ' → ' . $toName;
	}

	private static function actorLabel( string $actor ): string {
		return match ( $actor ) {
			'admin'    => __( 'Administración', 'hwe' ),
			'system'   => __( 'Sistema', 'hwe' ),
			'customer' => __( 'Cliente', 'hwe' ),
			default    => $actor,
		};
	}

	/** Nota o motivo guardado en los datos estructurados del evento. */
	private static function noteOf( object $row ): string {
		$data = $row->data ?? null;
		if ( is_string( $data ) && '' !== $data ) {
			$decoded = json_decode( $data, true );
			$data    = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $data ) ) {
			return '—';
		}

		$note = $data['note'] ?? ( $data['reason'] ?? '' );
		if ( ! is_scalar( $note ) ) {
			return '—';
		}
		$note = trim( (string) $note );

		return '' !== $note ? $note : '—';
	}

	private static function formatDate( string $utc ): string {
		if ( '' === $utc ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );
		return false === $timestamp ? $utc : wp_date( 'Y-m-d H:i', $timestamp );
	}
}
