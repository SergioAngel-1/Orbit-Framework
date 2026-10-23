<?php
/**
 * Columnas de saldo y referidor en el listado de usuarios (spec §12).
 *
 * La ordenación se hace en SQL: `pre_user_query` añade un `LEFT JOIN` a la tabla de saldos (o a
 * la de referidos) y cambia el `ORDER BY`. Nunca se cargan todos los usuarios para ordenarlos
 * en PHP. El valor de cada celda se resuelve con una consulta por fila (como el resto de
 * columnas nativas) y se guarda en una caché estática de la petición para no repetirla.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_UserColumns {

	public const BALANCE  = 'hwe_loyalty_balance';
	public const REFERRER = 'hwe_loyalty_referrer';

	/** @var array<int,int> */
	private static array $balances = array();
	/** @var array<int,?int> */
	private static array $referrers = array();

	/** Engancha las columnas. La llama `HWE_Loyalty_Admin::register()`. */
	public static function register(): void {
		add_filter( 'manage_users_columns', array( self::class, 'columns' ) );
		add_filter( 'manage_users_sortable_columns', array( self::class, 'sortable' ) );
		add_filter( 'manage_users_custom_column', array( self::class, 'render' ), 10, 3 );
		add_action( 'pre_user_query', array( self::class, 'applySort' ) );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function columns( array $columns ): array {
		if ( ! HWE_Loyalty_Admin_Capabilities::canView() ) {
			return $columns;
		}

		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'email' === $key ) {
				$new[ self::BALANCE ]  = __( 'Saldo', 'hwe' );
				$new[ self::REFERRER ] = __( 'Referidor', 'hwe' );
			}
		}

		// WooCommerce puede no exponer «email»: en ese caso van al final, nunca se pierden.
		if ( ! isset( $new[ self::BALANCE ] ) ) {
			$new[ self::BALANCE ]  = __( 'Saldo', 'hwe' );
			$new[ self::REFERRER ] = __( 'Referidor', 'hwe' );
		}

		return $new;
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function sortable( array $columns ): array {
		if ( ! HWE_Loyalty_Admin_Capabilities::canView() ) {
			return $columns;
		}

		$columns[ self::BALANCE ]  = self::BALANCE;
		$columns[ self::REFERRER ] = self::REFERRER;
		return $columns;
	}

	/**
	 * Celda devuelta al listado (el filtro espera texto, no ecos). El contenido va escapado.
	 *
	 * WordPress la llama como `manage_users_custom_column( $output, $column_name, $user_id )` y
	 * el id llega como cadena: los tipos se convierten aquí, no en la firma.
	 *
	 * @param mixed $value  Valor que traen otras columnas; se devuelve en las que no son nuestras.
	 * @param mixed $column Nombre de la columna.
	 * @param mixed $userId Id del usuario de la fila.
	 */
	public static function render( mixed $value, mixed $column, mixed $userId ): string {
		$value  = is_scalar( $value ) ? (string) $value : '';
		$column = is_scalar( $column ) ? (string) $column : '';
		$userId = is_numeric( $userId ) ? (int) $userId : 0;

		if ( ! HWE_Loyalty_Admin_Capabilities::canView() ) {
			return $value;
		}

		if ( self::BALANCE === $column ) {
			return esc_html( number_format_i18n( self::balanceOf( $userId ) ) );
		}

		if ( self::REFERRER === $column ) {
			$referrerId = self::referrerOf( $userId );
			if ( null === $referrerId ) {
				return '—';
			}

			return sprintf(
				'<a href="%s">%s</a>',
				esc_url( HWE_Loyalty_Admin_Users::profileUrl( $referrerId ) ),
				esc_html( HWE_Loyalty_Admin_Users::label( $referrerId ) )
			);
		}

		return $value;
	}

	/**
	 * Modifica la consulta de usuarios cuando el listado se ordena por una columna del módulo.
	 * Acepta el objeto de `pre_user_query` sin tiparlo para poder probarlo sin WordPress.
	 *
	 * @param mixed $query
	 */
	public static function applySort( $query ): void {
		if ( ! HWE_Loyalty_Admin_Capabilities::canView() ) {
			return;
		}
		if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) ) {
			return;
		}

		$orderby = (string) $query->get( 'orderby' );
		if ( ! in_array( $orderby, array( self::BALANCE, self::REFERRER ), true ) ) {
			return;
		}

		global $wpdb;
		$direction = strtoupper( (string) $query->get( 'order' ) );
		$direction = 'DESC' === $direction ? 'DESC' : 'ASC';
		$users     = isset( $wpdb->users ) ? (string) $wpdb->users : $wpdb->prefix . 'users';

		if ( self::BALANCE === $orderby ) {
			$table = HWE_Loyalty_Schema::balancesTable();
			$query->query_from .= " LEFT JOIN {$table} hwe_lb ON hwe_lb.user_id = {$users}.ID";
			$query->query_orderby = "ORDER BY COALESCE(hwe_lb.balance, 0) {$direction}";
			return;
		}

		$table = HWE_Loyalty_Schema::referralsTable();
		$query->query_from .= " LEFT JOIN {$table} hwe_lr ON hwe_lr.user_id = {$users}.ID";
		$query->query_orderby = "ORDER BY COALESCE(hwe_lr.referrer_id, 0) {$direction}";
	}

	private static function balanceOf( int $userId ): int {
		if ( ! array_key_exists( $userId, self::$balances ) ) {
			self::$balances[ $userId ] = (int) HWE_Loyalty_Ledger::balance( $userId )['balance'];
		}

		return self::$balances[ $userId ];
	}

	private static function referrerOf( int $userId ): ?int {
		if ( ! array_key_exists( $userId, self::$referrers ) ) {
			self::$referrers[ $userId ] = \HWE_Loyalty_Referrals::referrerOf( $userId, false );
		}

		return self::$referrers[ $userId ];
	}
}
