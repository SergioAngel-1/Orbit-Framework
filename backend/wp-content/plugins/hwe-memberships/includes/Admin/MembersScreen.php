<?php
/**
 * Listado de socios (spec §11.1): filtros, ordenación, paginación y acciones por fila.
 *
 * Todo se resuelve en SQL con `LIMIT/OFFSET`: ni el listado ni sus totales cargan todos los
 * usuarios. La fuente es la tabla de socios (la caché transaccional que mantiene
 * `HWE_Memberships_Grants`), unida a `wp_users` solo para el nombre visible; el email y el login
 * no salen de aquí. El detalle exacto de un socio (cola, concesiones, cuota) vive en su perfil.
 *
 * Los valores que se interpolan en la consulta son ids numéricos, estados y orígenes de una
 * lista blanca y fechas generadas con `gmdate`: ningún texto de la petición llega al SQL.
 *
 * Acciones por fila: congelar, descongelar y quitar. Van por `admin-post.php` con capacidad,
 * nonce **por fila y por acción**, y delegan en `HWE_Memberships_Grants`; esta pantalla no
 * escribe concesiones por su cuenta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_Members_Screen {

	/** Acción de `admin-post.php` para las acciones por fila. */
	public const ACTION = 'hwe_memberships_member_action';

	/** Campo del nonce por fila. */
	public const NONCE_FIELD = 'hwe_memberships_member_nonce';

	/** Prefijo de la acción del nonce; se ata al usuario y a la acción concreta. */
	public const NONCE_PREFIX = 'hwe_memberships_member';

	/** Filas por página. */
	public const PER_PAGE = 20;

	/** Días de la ventana de «por caducar» del filtro. */
	public const EXPIRING_DAYS = 30;

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_FROZEN   = 'frozen';
	public const STATUS_EXPIRING = 'expiring';
	public const STATUS_EXPIRED  = 'expired';

	public const ORDER_RECENT = 'recent';
	public const ORDER_LEVEL  = 'level';
	public const ORDER_ENDS   = 'ends';
	public const ORDER_MEMBER = 'member';
	public const ORDER_NAME   = 'name';

	/** Estados admitidos por el filtro; cualquier otro valor se ignora. */
	private const STATUSES = array(
		self::STATUS_ACTIVE,
		self::STATUS_FROZEN,
		self::STATUS_EXPIRING,
		self::STATUS_EXPIRED,
	);

	/** @return list<string> Estados que entiende el filtro. */
	public static function statuses(): array {
		return self::STATUSES;
	}

	/** Ordenaciones admitidas. */
	private const ORDERS = array(
		self::ORDER_RECENT,
		self::ORDER_LEVEL,
		self::ORDER_ENDS,
		self::ORDER_MEMBER,
		self::ORDER_NAME,
	);

	/** Longitud máxima de la nota de una acción. */
	private const NOTE_MAX = 500;

	/** Engancha las acciones por fila. La llama `HWE_Memberships_Admin::register()`. */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handleAction' ) );
	}

	// ------------------------------------------------------------------------
	//  Consulta
	// ------------------------------------------------------------------------

	/**
	 * Filtros saneados de la petición. Un valor desconocido vuelve a su valor por defecto en vez
	 * de propagarse; la consulta nunca recibe texto libre.
	 *
	 * @param array<string,mixed> $source Normalmente `$_GET`.
	 * @return array{level:int,status:string,origin:string,orderby:string,order:string,paged:int}
	 */
	public static function filters( array $source ): array {
		$scalar = static function ( string $key ) use ( $source ): string {
			return isset( $source[ $key ] ) && is_scalar( $source[ $key ] )
				? (string) wp_unslash( $source[ $key ] )
				: '';
		};

		$level = absint( $scalar( 'hwe_memberships_level' ) );

		$status = sanitize_key( $scalar( 'hwe_memberships_status' ) );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = '';
		}

		$origin = sanitize_key( $scalar( 'hwe_memberships_origin' ) );
		if ( ! in_array( $origin, HWE_Memberships_Grants::BASE_ORIGINS, true ) ) {
			$origin = '';
		}

		$orderby = sanitize_key( $scalar( 'hwe_memberships_orderby' ) );
		if ( '' === $orderby || ! in_array( $orderby, self::ORDERS, true ) ) {
			$orderby = self::ORDER_RECENT;
		}

		$order = strtolower( sanitize_key( $scalar( 'hwe_memberships_order' ) ) );
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = 'asc';
		}

		$paged = (int) $scalar( 'paged' );

		return array(
			'level'   => $level,
			'status'  => $status,
			'origin'  => $origin,
			'orderby' => $orderby,
			'order'   => $order,
			'paged'   => max( 1, $paged ),
		);
	}

	/**
	 * Condición SQL de un estado del socio. Devuelve cadena vacía si el estado no aplica.
	 *
	 * @param string $alias  Alias de la tabla de socios (`m` en el listado, `hwe_mm` en usuarios).
	 * @param string $nowSql Ahora en UTC, ya saneado.
	 * @return array{0:string,1:string} Condición y fin de la ventana de caducidad.
	 */
	public static function statusWhere( string $status, string $alias = 'm', ?string $nowSql = null ): array {
		$nowSql = null === $nowSql || '' === $nowSql ? gmdate( 'Y-m-d H:i:s', time() ) : $nowSql;
		// La caducidad manda por fechas, no por lo que la tarea diaria ya haya materializado.
		$active = "{$alias}.frozen_at IS NULL AND {$alias}.level_id IS NOT NULL AND {$alias}.level_id > 0"
			. " AND ({$alias}.covers_until IS NULL OR {$alias}.covers_until > '{$nowSql}')";

		return match ( $status ) {
			self::STATUS_ACTIVE   => array( $active, '' ),
			self::STATUS_FROZEN   => array( "{$alias}.frozen_at IS NOT NULL", '' ),
			self::STATUS_EXPIRING => array(
				$active . " AND {$alias}.covers_until IS NOT NULL"
					. " AND {$alias}.covers_until > '{$nowSql}'"
					. " AND {$alias}.covers_until <= '" . self::until( $nowSql ) . "'",
				self::until( $nowSql ),
			),
			self::STATUS_EXPIRED  => array(
				"{$alias}.frozen_at IS NULL AND ({$alias}.level_id IS NULL OR {$alias}.level_id = 0)"
					. " AND {$alias}.member_since IS NOT NULL",
				'',
			),
			default               => array( '', '' ),
		};
	}

	/**
	 * WHERE completo del listado, compartido con el conteo y con la columna de usuarios.
	 *
	 * @param array{level:int,status:string,origin:string,orderby:string,order:string,paged:int} $filters
	 */
	public static function where( array $filters, string $alias = 'm', ?string $nowSql = null ): string {
		$parts = array( '1=1' );

		if ( (int) $filters['level'] > 0 ) {
			$parts[] = "{$alias}.level_id = " . (int) $filters['level'];
		}

		[ $status ] = self::statusWhere( (string) $filters['status'], $alias, $nowSql );
		if ( '' !== $status ) {
			$parts[] = $status;
		}

		$origin = (string) ( $filters['origin'] ?? '' );
		if ( '' !== $origin && in_array( $origin, HWE_Memberships_Grants::BASE_ORIGINS, true ) ) {
			$grants  = HWE_Memberships_Schema::grantsTable();
			$parts[] = "EXISTS (SELECT 1 FROM {$grants} g WHERE g.user_id = {$alias}.user_id"
				. " AND g.status IN ('active','scheduled')"
				. " AND g.origin = '" . $origin . "')";
		}

		return implode( ' AND ', $parts );
	}

	/** ORDER BY de una ordenación admitida; el resto cae en «más recientes». */
	public static function orderBy( array $filters ): string {
		global $wpdb;

		$direction = 'desc' === (string) $filters['order'] ? 'DESC' : 'ASC';

		switch ( (string) $filters['orderby'] ) {
			case self::ORDER_LEVEL:
				$postmeta = $wpdb->prefix . 'postmeta';
				$rank     = HWE_Memberships_Levels::RANK_META;
				return "ORDER BY COALESCE((SELECT pm.meta_value + 0 FROM {$postmeta} pm"
					. " WHERE pm.post_id = m.level_id AND pm.meta_key = '{$rank}' LIMIT 1), 0) {$direction}, m.user_id ASC";
			case self::ORDER_ENDS:
				return "ORDER BY m.covers_until {$direction}, m.user_id ASC";
			case self::ORDER_MEMBER:
				return "ORDER BY m.member_number {$direction}, m.user_id ASC";
			case self::ORDER_NAME:
				return "ORDER BY u.display_name {$direction}, m.user_id ASC";
			case self::ORDER_RECENT:
			default:
				return 'ORDER BY m.member_since DESC, m.user_id DESC';
		}
	}

	/**
	 * Página de socios.
	 *
	 * @param array{level:int,status:string,origin:string,orderby:string,order:string,paged:int} $filters
	 * @return list<object>
	 */
	public static function items( array $filters, int $limit = self::PER_PAGE, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 1000, $limit ) );
		$offset = max( 0, $offset );

		$members = HWE_Memberships_Schema::membersTable();
		$users   = $wpdb->prefix . 'users';
		$where   = self::where( $filters );
		$order   = self::orderBy( $filters );

		$sql = "SELECT m.user_id, m.level_id, m.covers_until, m.frozen_at, m.member_number, m.member_since, u.display_name"
			. " FROM {$members} m"
			. " LEFT JOIN {$users} u ON u.ID = m.user_id"
			. " WHERE {$where} {$order} LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total de socios que cumplen los filtros, sin traer las filas.
	 *
	 * @param array{level:int,status:string,origin:string,orderby:string,order:string,paged:int} $filters
	 */
	public static function count( array $filters ): int {
		global $wpdb;

		$members = HWE_Memberships_Schema::membersTable();
		$where   = self::where( $filters );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$members} m WHERE {$where}" );
	}

	/**
	 * @param array{level:int,status:string,origin:string,orderby:string,order:string,paged:int} $filters
	 * @return array{items:list<object>,total:int}
	 */
	public static function query( array $filters, int $limit = self::PER_PAGE, ?int $offset = null ): array {
		$offset = null === $offset ? max( 0, ( (int) $filters['paged'] - 1 ) * self::PER_PAGE ) : max( 0, $offset );

		return array(
			'items' => self::items( $filters, $limit, $offset ),
			'total' => self::count( $filters ),
		);
	}

	// ------------------------------------------------------------------------
	//  Presentación
	// ------------------------------------------------------------------------

	/** Pantalla del listado. */
	public static function render(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			wp_die( esc_html__( 'No tienes permiso para ver los socios.', 'hwe' ) );
			return;
		}

		$filters = self::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$offset  = ( (int) $filters['paged'] - 1 ) * self::PER_PAGE;
		$result  = self::query( $filters, self::PER_PAGE, $offset );
		$canEdit = HWE_Memberships_Admin_Capabilities::canManage();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Socios', 'hwe' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::exportUrl( $filters ) ) . '">'
			. esc_html__( 'Exportar CSV', 'hwe' ) . '</a>';
		echo '<hr class="wp-header-end">';

		HWE_Memberships_Admin::renderNotice( HWE_Memberships_Admin::pullFlash() );

		self::renderFilters( $filters );

		if ( array() === $result['items'] ) {
			echo '<p><em>' . esc_html__( 'No hay socios que cumplan los filtros.', 'hwe' ) . '</em></p>';
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Socio', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Número', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Nivel', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Estado', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Fin', 'hwe' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Acciones', 'hwe' ) . '</th>';
		echo '</tr></thead><tbody>';

		self::renderRows( $result['items'], $canEdit );

		echo '</tbody></table>';

		self::renderPagination( (int) $result['total'], (int) $filters['paged'], $filters );
		echo '</div>';
	}

	/**
	 * Cuerpo de la tabla. Separado del render para probar el escapado sin montar la pantalla.
	 *
	 * @param list<object> $rows
	 */
	private static function renderRows( array $rows, bool $canManage ): void {
		$nowSql = gmdate( 'Y-m-d H:i:s', time() );

		foreach ( $rows as $row ) {
			$userId = (int) ( $row->user_id ?? 0 );
			$status = self::statusOf( $row, $nowSql );
			$level  = (int) ( $row->level_id ?? 0 ) > 0 ? HWE_Memberships_Levels::get( (int) $row->level_id ) : null;
			$name   = isset( $row->display_name ) ? (string) $row->display_name : '';

			echo '<tr>';
			echo '<td><strong>';
			if ( $userId > 0 ) {
				printf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'user-edit.php?user_id=' . $userId ) ),
					esc_html( '' !== $name ? $name : '#' . $userId )
				);
			} else {
				echo '—';
			}
			echo '</strong></td>';
			echo '<td>' . esc_html( trim( (string) ( $row->member_number ?? '' ) ) ?: '—' ) . '</td>';
			echo '<td>' . esc_html( null === $level ? '—' : (string) $level['name'] ) . '</td>';
			echo '<td>' . esc_html( self::statusLabel( $status ) ) . '</td>';
			echo '<td>' . esc_html( self::formatDate( (string) ( $row->covers_until ?? '' ) ) ) . '</td>';
			echo '<td>';
			if ( $canManage && $userId > 0 ) {
				self::renderRowAction( $userId, self::STATUS_FROZEN === $status ? 'unfreeze' : 'freeze', self::STATUS_FROZEN === $status
					? __( 'Descongelar', 'hwe' )
					: __( 'Congelar', 'hwe' ) );
				echo ' ';
				self::renderRowAction( $userId, 'remove', __( 'Quitar', 'hwe' ) );
			} else {
				echo '—';
			}
			echo '</td>';
			echo '</tr>';
		}
	}

	/** Un botón de acción por fila, con su nonce atado al usuario y a la acción. */
	private static function renderRowAction( int $userId, string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $userId ) . '">';
		echo '<input type="hidden" name="hwe_memberships_action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( self::nonceAction( $userId, $action ), self::NONCE_FIELD );
		echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Formulario de filtros. Solo pinta niveles que existan y los estados/orígenes admitidos.
	 *
	 * @param array{level:int,status:string,origin:string,orderby:string,order:string,paged:int} $filters
	 */
	public static function renderFilters( array $filters ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( HWE_Memberships_Admin_Menu::slug( HWE_Memberships_Admin_Menu::MEMBERS ) ) . '">';

		echo '<label for="hwe-memberships-level" class="screen-reader-text">' . esc_html__( 'Nivel', 'hwe' ) . '</label>';
		echo '<select id="hwe-memberships-level" name="hwe_memberships_level">';
		echo '<option value="0">' . esc_html__( 'Todos los niveles', 'hwe' ) . '</option>';
		foreach ( HWE_Memberships_Levels::all( true ) as $level ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) (int) $level['id'] ),
				(int) $level['id'] === (int) $filters['level'] ? ' selected="selected"' : '',
				esc_html( (string) $level['name'] )
			);
		}
		echo '</select> ';

		echo '<label for="hwe-memberships-status" class="screen-reader-text">' . esc_html__( 'Estado', 'hwe' ) . '</label>';
		echo '<select id="hwe-memberships-status" name="hwe_memberships_status">';
		echo '<option value="">' . esc_html__( 'Cualquier estado', 'hwe' ) . '</option>';
		foreach ( self::STATUSES as $status ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $status ),
				$status === (string) $filters['status'] ? ' selected="selected"' : '',
				esc_html( self::statusLabel( $status ) )
			);
		}
		echo '</select> ';

		echo '<label for="hwe-memberships-origin" class="screen-reader-text">' . esc_html__( 'Origen', 'hwe' ) . '</label>';
		echo '<select id="hwe-memberships-origin" name="hwe_memberships_origin">';
		echo '<option value="">' . esc_html__( 'Cualquier origen', 'hwe' ) . '</option>';
		foreach ( self::originLabels() as $origin => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $origin ),
				$origin === (string) $filters['origin'] ? ' selected="selected"' : '',
				esc_html( $label )
			);
		}
		echo '</select> ';

		echo '<label for="hwe-memberships-orderby" class="screen-reader-text">' . esc_html__( 'Ordenar por', 'hwe' ) . '</label>';
		echo '<select id="hwe-memberships-orderby" name="hwe_memberships_orderby">';
		foreach ( self::orderLabels() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				$key === (string) $filters['orderby'] ? ' selected="selected"' : '',
				esc_html( $label )
			);
		}
		echo '</select> ';

		echo '<label for="hwe-memberships-order" class="screen-reader-text">' . esc_html__( 'Dirección', 'hwe' ) . '</label>';
		echo '<select id="hwe-memberships-order" name="hwe_memberships_order">';
		echo '<option value="asc"' . ( 'asc' === (string) $filters['order'] ? ' selected="selected"' : '' ) . '>'
			. esc_html__( 'Ascendente', 'hwe' ) . '</option>';
		echo '<option value="desc"' . ( 'desc' === (string) $filters['order'] ? ' selected="selected"' : '' ) . '>'
			. esc_html__( 'Descendente', 'hwe' ) . '</option>';
		echo '</select> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Filtrar', 'hwe' ) . '</button>';
		echo '</form>';
	}

	private static function renderPagination( int $total, int $paged, array $filters ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( sprintf(
					/* translators: %d: número de socios. */
					_n( '%d socio.', '%d socios.', $total, 'hwe' ),
					$total
				) )
			);
			return;
		}

		echo '<p class="tablenav-pages">';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( $filters, $paged - 1 ) ) . '">'
				. esc_html__( 'Anterior', 'hwe' ) . '</a> ';
		}
		printf(
			'<span class="displaying-num">%s</span> ',
			esc_html( sprintf(
				/* translators: 1: página actual, 2: total de páginas, 3: total de socios. */
				__( 'Página %1$d de %2$d · %3$d socios', 'hwe' ),
				$paged,
				$pages,
				$total
			) )
		);
		if ( $paged < $pages ) {
			echo '<a class="button" href="' . esc_url( self::url( $filters, $paged + 1 ) ) . '">'
				. esc_html__( 'Siguiente', 'hwe' ) . '</a>';
		}
		echo '</p>';
	}

	/** URL del listado conservando los filtros. */
	public static function url( array $filters, int $paged = 1 ): string {
		$args = array();
		foreach ( array( 'level', 'status', 'origin', 'orderby', 'order' ) as $key ) {
			$value = $filters[ $key ] ?? '';
			if ( '' !== $value && 0 !== $value && self::ORDER_RECENT !== $value ) {
				$args[ 'hwe_memberships_' . $key ] = $value;
			}
		}
		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		return HWE_Memberships_Admin_Menu::url( HWE_Memberships_Admin_Menu::MEMBERS, $args );
	}

	/** URL de la exportación con el nonce y los filtros vigentes. */
	public static function exportUrl( array $filters ): string {
		$args = array(
			'action'   => HWE_Memberships_Admin_Csv::ACTION,
			'_wpnonce' => wp_create_nonce( HWE_Memberships_Admin_Csv::NONCE ),
		);
		foreach ( array( 'level', 'status', 'origin', 'orderby', 'order' ) as $key ) {
			$value = $filters[ $key ] ?? '';
			if ( '' !== $value && 0 !== $value && self::ORDER_RECENT !== $value ) {
				$args[ 'hwe_memberships_' . $key ] = $value;
			}
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	// ------------------------------------------------------------------------
	//  Acciones por fila
	// ------------------------------------------------------------------------

	/** Acción del nonce de una fila y una acción concretas. */
	public static function nonceAction( int $userId, string $action ): string {
		return self::NONCE_PREFIX . ':' . $userId . ':' . $action;
	}

	/** POST de `admin-post.php`: capacidad, nonce por fila, acción y aviso. */
	public static function handleAction(): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canManage() ) {
			wp_die( esc_html__( 'No autorizado.', 'hwe' ) );
			return;
		}

		$userId = isset( $_POST['user_id'] ) && is_scalar( $_POST['user_id'] )
			? absint( wp_unslash( $_POST['user_id'] ) )
			: 0;
		$action = isset( $_POST['hwe_memberships_action'] ) && is_scalar( $_POST['hwe_memberships_action'] )
			? sanitize_key( wp_unslash( (string) $_POST['hwe_memberships_action'] ) )
			: '';
		$note   = isset( $_POST['hwe_memberships_note'] ) && is_scalar( $_POST['hwe_memberships_note'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['hwe_memberships_note'] ) )
			: '';
		$nonce  = isset( $_POST[ self::NONCE_FIELD ] ) && is_scalar( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::nonceAction( $userId, $action ) ) ) {
			wp_die( esc_html__( 'Nonce no válido.', 'hwe' ) );
			return;
		}

		$result = self::applyRowAction( $userId, $action, $note );
		HWE_Memberships_Admin::flash( $result['code'] );

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( self::url( self::filters( array() ) ) );
		}
	}

	/**
	 * Ejecuta una acción sobre un socio. Devuelve un código de aviso; nunca pinta.
	 *
	 * Códigos: `member_frozen`, `member_unfrozen`, `member_removed`, `invalid_user`,
	 * `invalid_action` y `not_possible`.
	 *
	 * @return array{ok:bool,code:string}
	 */
	public static function applyRowAction( int $userId, string $action, string $note = '' ): array {
		if ( ! HWE_Memberships_Admin_Capabilities::canManage() ) {
			return array( 'ok' => false, 'code' => 'forbidden' );
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $userId ) : false;
		if ( $userId <= 0 || ! is_object( $user ) ) {
			return array( 'ok' => false, 'code' => 'invalid_user' );
		}

		$actorId = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$note    = self::cleanNote( $note );

		switch ( $action ) {
			case 'freeze':
				$ok = HWE_Memberships_Grants::freeze( $userId, $actorId );
				return array( 'ok' => $ok, 'code' => $ok ? 'member_frozen' : 'not_possible' );

			case 'unfreeze':
				$ok = HWE_Memberships_Grants::unfreeze( $userId, $actorId );
				return array( 'ok' => $ok, 'code' => $ok ? 'member_unfrozen' : 'not_possible' );

			case 'remove':
				$ok = HWE_Memberships_Grants::removeAll(
					$userId,
					$actorId,
					'' !== $note ? $note : 'admin_removed'
				);
				return array( 'ok' => $ok, 'code' => $ok ? 'member_removed' : 'not_possible' );
		}

		return array( 'ok' => false, 'code' => 'invalid_action' );
	}

	// ------------------------------------------------------------------------
	//  Utilidades de presentación
	// ------------------------------------------------------------------------

	/**
	 * Estado de una fila del listado, calculado por fechas. La caché de socios puede no haber
	 * materializado todavía una caducidad; aquí manda el cálculo.
	 *
	 * @param mixed $row
	 */
	public static function statusOf( mixed $row, string $nowSql ): string {
		if ( ! is_object( $row ) && ! is_array( $row ) ) {
			return self::STATUS_EXPIRED;
		}

		$get = static function ( string $key ) use ( $row ): mixed {
			if ( is_array( $row ) ) {
				return $row[ $key ] ?? null;
			}
			return $row->{$key} ?? null;
		};

		if ( null !== $get( 'frozen_at' ) && '' !== $get( 'frozen_at' ) ) {
			return self::STATUS_FROZEN;
		}

		if ( (int) $get( 'level_id' ) <= 0 ) {
			return self::STATUS_EXPIRED;
		}

		$coversUntil = $get( 'covers_until' );
		if ( null !== $coversUntil && '' !== $coversUntil ) {
			$coversUntil = (string) $coversUntil;
			if ( $coversUntil <= $nowSql ) {
				return self::STATUS_EXPIRED;
			}
			if ( $coversUntil <= self::until( $nowSql ) ) {
				return self::STATUS_EXPIRING;
			}
		}

		return self::STATUS_ACTIVE;
	}

	/** Etiqueta de UI de un estado. */
	public static function statusLabel( string $status ): string {
		return match ( $status ) {
			self::STATUS_ACTIVE   => __( 'Activo', 'hwe' ),
			self::STATUS_FROZEN   => __( 'Congelado', 'hwe' ),
			self::STATUS_EXPIRING => __( 'Por caducar', 'hwe' ),
			self::STATUS_EXPIRED  => __( 'Caducado', 'hwe' ),
			default               => __( 'Sin membresía', 'hwe' ),
		};
	}

	/** @return array<string,string> */
	public static function orderLabels(): array {
		return array(
			self::ORDER_RECENT => __( 'Más recientes', 'hwe' ),
			self::ORDER_LEVEL  => __( 'Nivel', 'hwe' ),
			self::ORDER_ENDS   => __( 'Fin de la cobertura', 'hwe' ),
			self::ORDER_MEMBER => __( 'Número de socio', 'hwe' ),
			self::ORDER_NAME   => __( 'Nombre visible', 'hwe' ),
		);
	}

	/** @return array<string,string> */
	public static function originLabels(): array {
		return array(
			HWE_Memberships_Grants::ORIGIN_ORDER         => __( 'Compra', 'hwe' ),
			HWE_Memberships_Grants::ORIGIN_ADMIN         => __( 'Administración', 'hwe' ),
			HWE_Memberships_Grants::ORIGIN_BULK          => __( 'Lote', 'hwe' ),
			HWE_Memberships_Grants::ORIGIN_REFERRAL_GIFT => __( 'Regalo de referido', 'hwe' ),
		);
	}

	/** Nota de una acción: saneada y acotada; vacía si no aporta motivo. */
	private static function cleanNote( string $note ): string {
		$note = trim( sanitize_text_field( $note ) );
		if ( '' === $note ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, self::NOTE_MAX ) : substr( $note, 0, self::NOTE_MAX );
	}

	/** Fecha de MySQL en UTC para «ahora + N días». */
	private static function until( string $nowSql ): string {
		$timestamp = strtotime( $nowSql . ' UTC' );
		$base      = false === $timestamp ? time() : $timestamp;

		return gmdate( 'Y-m-d H:i:s', $base + self::EXPIRING_DAYS * DAY_IN_SECONDS );
	}

	private static function formatDate( string $utc ): string {
		if ( '' === $utc ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );
		return false === $timestamp ? $utc : wp_date( 'Y-m-d H:i', $timestamp );
	}
}
