<?php
/**
 * Columna de membresía en el listado de usuarios y sus filtros (spec §11.2).
 *
 * La ordenación y el filtro se resuelven **en SQL**: `pre_user_query` añade un `LEFT JOIN` a la
 * tabla de socios y cambia el `ORDER BY` o el `WHERE`. Nunca se cargan todos los usuarios para
 * resolverlo en PHP. El valor de cada celda se lee con una consulta por fila (como el resto de
 * columnas nativas) y se guarda en una caché estática de la petición para no repetirla.
 *
 * Los únicos valores que llegan al SQL son ids numéricos, estados de una lista blanca y fechas
 * generadas con `gmdate`; ningún texto libre de la petición.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_Admin_User_Columns {

	/** Clave de la columna en el listado de usuarios. */
	public const COLUMN = 'hwe_memberships';

	/** Parámetros GET del filtro. */
	public const LEVEL_QUERY  = 'hwe_memberships_level';
	public const STATUS_QUERY = 'hwe_memberships_status';

	/** Alias de la tabla de socios en la consulta de usuarios. */
	private const ALIAS = 'hwe_mm';

	/** @var array<int,object|null> Caché de la petición: user_id → fila de socios. */
	private static array $rows = array();

	/** Engancha la columna y sus filtros. La llama `HWE_Memberships_Admin::register()`. */
	public static function register(): void {
		add_filter( 'manage_users_columns', array( self::class, 'columns' ) );
		add_filter( 'manage_users_sortable_columns', array( self::class, 'sortable' ) );
		add_filter( 'manage_users_custom_column', array( self::class, 'render' ), 10, 3 );
		add_action( 'pre_user_query', array( self::class, 'applySort' ) );
		add_action( 'pre_user_query', array( self::class, 'applyFilter' ) );
		add_action( 'restrict_manage_users', array( self::class, 'renderFilters' ) );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function columns( array $columns ): array {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			return $columns;
		}

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'email' === $key ) {
				$new[ self::COLUMN ] = __( 'Membresía', 'hwe' );
			}
		}

		// WooCommerce puede no exponer «email»: en ese caso va al final, nunca se pierde.
		if ( ! isset( $new[ self::COLUMN ] ) ) {
			$new[ self::COLUMN ] = __( 'Membresía', 'hwe' );
		}

		return $new;
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function sortable( array $columns ): array {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = self::COLUMN;

		return $columns;
	}

	/**
	 * Celda de la columna. WordPress la llama como
	 * `manage_users_custom_column( $output, $column_name, $user_id )` y el id llega como cadena.
	 *
	 * @param mixed $value
	 * @param mixed $column
	 * @param mixed $userId
	 */
	public static function render( mixed $value, mixed $column, mixed $userId ): string {
		$value  = is_scalar( $value ) ? (string) $value : '';
		$column = is_scalar( $column ) ? (string) $column : '';
		$userId = is_numeric( $userId ) ? (int) $userId : 0;

		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			return $value;
		}
		if ( self::COLUMN !== $column ) {
			return $value;
		}

		$row = self::rowOf( $userId );
		if ( null === $row || (int) ( $row->level_id ?? 0 ) <= 0 ) {
			return '—';
		}

		$level = HWE_Memberships_Levels::get( (int) $row->level_id );
		if ( null === $level ) {
			return '—';
		}

		$status = HWE_Memberships_Admin_Members_Screen::statusOf(
			$row,
			gmdate( 'Y-m-d H:i:s', time() )
		);

		$html = sprintf(
			'<a href="%s"><strong>%s</strong></a>',
			esc_url( admin_url( 'user-edit.php?user_id=' . $userId ) ),
			esc_html( (string) $level['name'] )
		);

		$until = (string) ( $row->covers_until ?? '' );
		if ( '' !== $until ) {
			$timestamp = strtotime( $until . ' UTC' );
			$html     .= '<br /><span class="description">'
				. esc_html( sprintf(
					/* translators: %s: fecha de fin de la membresía. */
					__( 'hasta %s', 'hwe' ),
					false === $timestamp ? $until : wp_date( 'Y-m-d', $timestamp )
				) )
				. '</span>';
		}

		if ( HWE_Memberships_Admin_Members_Screen::STATUS_FROZEN === $status ) {
			$html .= ' <span class="description">' . esc_html__( '· congelado', 'hwe' ) . '</span>';
		} elseif ( HWE_Memberships_Admin_Members_Screen::STATUS_EXPIRED === $status ) {
			$html .= ' <span class="description">' . esc_html__( '· caducado', 'hwe' ) . '</span>';
		}

		return $html;
	}

	/**
	 * Ordenación por la columna: JOIN a socios y ORDER BY en SQL.
	 *
	 * @param mixed $query `WP_User_Query`; sin tipar para poder probarlo sin WordPress.
	 */
	public static function applySort( $query ): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			return;
		}
		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return;
		}
		if ( self::COLUMN !== (string) $query->get( 'orderby' ) ) {
			return;
		}

		self::join( $query );
		$direction = strtoupper( (string) $query->get( 'order' ) );
		$direction = 'DESC' === $direction ? 'DESC' : 'ASC';
		$query->query_orderby = 'ORDER BY COALESCE(' . self::ALIAS . '.level_id, 0) ' . $direction;
	}

	/**
	 * Filtro por nivel y estado desde el listado de usuarios, resuelto en SQL.
	 *
	 * @param mixed $query `WP_User_Query`.
	 */
	public static function applyFilter( $query ): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			return;
		}
		if ( ! is_object( $query ) || ! property_exists( $query, 'query_where' ) ) {
			return;
		}

		$level = isset( $_GET[ self::LEVEL_QUERY ] ) && is_scalar( $_GET[ self::LEVEL_QUERY ] )
			? absint( wp_unslash( $_GET[ self::LEVEL_QUERY ] ) )
			: 0;
		$status = isset( $_GET[ self::STATUS_QUERY ] ) && is_scalar( $_GET[ self::STATUS_QUERY ] )
			? sanitize_key( wp_unslash( (string) $_GET[ self::STATUS_QUERY ] ) )
			: '';
		if ( ! in_array( $status, HWE_Memberships_Admin_Members_Screen::statuses(), true ) ) {
			$status = '';
		}

		$conditions = array();
		if ( $level > 0 ) {
			$conditions[] = self::ALIAS . '.level_id = ' . $level;
		}

		[ $statusSql ] = HWE_Memberships_Admin_Members_Screen::statusWhere(
			$status,
			self::ALIAS,
			gmdate( 'Y-m-d H:i:s', time() )
		);
		if ( '' !== $statusSql ) {
			$conditions[] = $statusSql;
		}

		if ( array() === $conditions ) {
			return;
		}

		self::join( $query );
		$query->query_where .= ' AND ' . implode( ' AND ', $conditions );
	}

	/** Pinta los dos selectores del filtro en el listado de usuarios. */
	public static function renderFilters( string $which = 'top' ): void {
		if ( ! HWE_Memberships_Admin_Capabilities::canView() ) {
			return;
		}

		$level  = isset( $_GET[ self::LEVEL_QUERY ] ) && is_scalar( $_GET[ self::LEVEL_QUERY ] )
			? absint( wp_unslash( $_GET[ self::LEVEL_QUERY ] ) )
			: 0;
		$status = isset( $_GET[ self::STATUS_QUERY ] ) && is_scalar( $_GET[ self::STATUS_QUERY ] )
			? sanitize_key( wp_unslash( (string) $_GET[ self::STATUS_QUERY ] ) )
			: '';

		echo '<label for="hwe-memberships-users-level" class="screen-reader-text">' . esc_html__( 'Nivel de membresía', 'hwe' ) . '</label>';
		echo '<select id="hwe-memberships-users-level" name="' . esc_attr( self::LEVEL_QUERY ) . '">';
		echo '<option value="0">' . esc_html__( 'Cualquier nivel', 'hwe' ) . '</option>';
		foreach ( HWE_Memberships_Levels::all( true ) as $item ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) (int) $item['id'] ),
				(int) $item['id'] === $level ? ' selected="selected"' : '',
				esc_html( (string) $item['name'] )
			);
		}
		echo '</select> ';

		echo '<label for="hwe-memberships-users-status" class="screen-reader-text">' . esc_html__( 'Estado de la membresía', 'hwe' ) . '</label>';
		echo '<select id="hwe-memberships-users-status" name="' . esc_attr( self::STATUS_QUERY ) . '">';
		echo '<option value="">' . esc_html__( 'Cualquier estado', 'hwe' ) . '</option>';
		foreach ( HWE_Memberships_Admin_Members_Screen::statuses() as $item ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $item ),
				$item === $status ? ' selected="selected"' : '',
				esc_html( HWE_Memberships_Admin_Members_Screen::statusLabel( $item ) )
			);
		}
		echo '</select> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Filtrar membresía', 'hwe' ) . '</button>';
	}

	/** Vacía la caché de la petición (la usan los tests y las peticiones largas). */
	public static function flushCache(): void {
		self::$rows = array();
	}

	/**
	 * Añade el JOIN a socios una sola vez, aunque coincidan ordenación y filtro.
	 *
	 * @param mixed $query
	 */
	private static function join( $query ): void {
		global $wpdb;

		$from = isset( $query->query_from ) && is_string( $query->query_from ) ? $query->query_from : '';
		if ( str_contains( $from, self::ALIAS ) ) {
			return;
		}

		$members = HWE_Memberships_Schema::membersTable();
		$users   = $wpdb->prefix . 'users';
		$query->query_from = $from . ' LEFT JOIN ' . $members . ' ' . self::ALIAS . ' ON '
			. self::ALIAS . '.user_id = ' . $users . '.ID';
	}

	/** Fila de socios del usuario, cacheada por petición. */
	private static function rowOf( int $userId ): ?object {
		if ( $userId <= 0 ) {
			return null;
		}
		if ( array_key_exists( $userId, self::$rows ) ) {
			return self::$rows[ $userId ];
		}

		global $wpdb;

		$members = HWE_Memberships_Schema::membersTable();
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, level_id, covers_until, frozen_at, member_number, member_since"
				. " FROM {$members} WHERE user_id = %d LIMIT 1",
				$userId
			)
		);

		self::$rows[ $userId ] = is_object( $row ) ? $row : null;

		return self::$rows[ $userId ];
	}
}
