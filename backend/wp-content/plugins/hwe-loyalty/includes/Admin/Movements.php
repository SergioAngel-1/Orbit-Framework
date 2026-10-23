<?php
/**
 * Consultas del listado de movimientos para wp-admin y su exportación (spec §12).
 *
 * Los filtros son los que pide el spec: usuario, tipo, fecha y pedido. Se aplican en SQL, con
 * `prepare`, y la consulta une la tabla de usuarios solo para traer el nombre visible; el email
 * y el login no salen de aquí (el CSV no los incluye). El listado y el CSV comparten esta clase
 * para que un filtro no signifique dos cosas distintas según la pantalla.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Movements {

	public const PER_PAGE = 20;
	public const CSV_BATCH = 500;

	/**
	 * Filtros saneados de la petición. Un usuario que no existe se representa con `-1` para que
	 * la consulta no devuelva nada en vez de ignorar el filtro en silencio.
	 *
	 * @param array<string,mixed> $source Normalmente `$_GET`.
	 * @return array{user_id:int,type:string,from:string,to:string,order_id:int}
	 */
	public static function filters( array $source ): array {
		$filters = array(
			'user_id'  => 0,
			'type'     => '',
			'from'     => '',
			'to'       => '',
			'order_id' => 0,
		);

		$term = isset( $source['hwe_user'] ) ? sanitize_text_field( wp_unslash( $source['hwe_user'] ) ) : '';
		if ( '' !== $term ) {
			$userId = HWE_Loyalty_Admin_Users::resolve( $term );
			$filters['user_id'] = null === $userId ? -1 : (int) $userId;
		}

		$type = isset( $source['hwe_type'] ) ? sanitize_key( wp_unslash( $source['hwe_type'] ) ) : '';
		if ( '' !== $type && \HWE_Loyalty_EntryTypes::exists( $type ) ) {
			$filters['type'] = $type;
		}

		foreach ( array( 'from', 'to' ) as $key ) {
			$value = isset( $source[ 'hwe_' . $key ] ) ? sanitize_text_field( wp_unslash( $source[ 'hwe_' . $key ] ) ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$filters[ $key ] = $value;
			}
		}

		$order = isset( $source['hwe_order'] ) ? absint( wp_unslash( $source['hwe_order'] ) ) : 0;
		if ( $order > 0 ) {
			$filters['order_id'] = $order;
		}

		return $filters;
	}

	/**
	 * Página de movimientos, del más nuevo al más antiguo.
	 *
	 * @param array{user_id:int,type:string,from:string,to:string,order_id:int} $filters
	 * @return array{items:list<object>,total:int}
	 */
	public static function query( array $filters, int $limit = self::PER_PAGE, int $offset = 0 ): array {
		return array(
			'items' => self::items( $filters, $limit, $offset ),
			'total' => self::count( $filters ),
		);
	}

	/**
	 * Solo la página pedida, sin contar el total. El CSV lo usa para no repetir el `COUNT(*)`
	 * en cada lote de streaming.
	 *
	 * @param array{user_id:int,type:string,from:string,to:string,order_id:int} $filters
	 * @return list<object>
	 */
	public static function items( array $filters, int $limit = self::PER_PAGE, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 1000, $limit ) );
		$offset = max( 0, $offset );
		[ $where, $args ] = self::where( $filters );

		$ledger = HWE_Loyalty_Schema::ledgerTable();
		$users  = $wpdb->users;
		$sql    = "SELECT l.*, u.display_name FROM {$ledger} l"
			. " LEFT JOIN {$users} u ON u.ID = l.user_id"
			. " WHERE {$where} ORDER BY l.created_at DESC, l.id DESC LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit, $offset ) ) ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array{user_id:int,type:string,from:string,to:string,order_id:int} $filters
	 */
	public static function count( array $filters ): int {
		global $wpdb;

		[ $where, $args ] = self::where( $filters );
		$ledger = HWE_Loyalty_Schema::ledgerTable();
		$sql    = "SELECT COUNT(*) FROM {$ledger} l WHERE {$where}";

		if ( array() !== $args ) {
			$sql = (string) $wpdb->prepare( $sql, $args );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/** Etiqueta de UI del tipo de movimiento. Nunca interpola el nombre de la unidad. */
	public static function typeLabel( string $type ): string {
		return match ( $type ) {
			'cashback'               => 'Devolución de la compra',
			'referral_commission_l1'  => 'Comisión de referido (nivel 1)',
			'referral_commission_l2'  => 'Comisión de referido (nivel 2)',
			'referral_product_bonus'  => 'Bono por producto',
			'referral_signup_l1'      => 'Alta de referido (nivel 1)',
			'referral_signup_l2'      => 'Alta de referido (nivel 2)',
			'registration'            => 'Alta de la cuenta',
			'review'                  => 'Reseña',
			'birthday'                => 'Cumpleaños',
			'transfer_in'             => 'Transferencia recibida',
			'redeem_refund'           => 'Devolución de un canje',
			'admin_credit'            => 'Abono manual',
			'redeem'                  => 'Canje en un pedido',
			'transfer_out'            => 'Transferencia enviada',
			'transfer_fee'            => 'Comisión de transferencia',
			'expire'                  => 'Caducidad',
			'admin_debit'             => 'Cargo manual',
			'earn_reversal'           => 'Anulación de ganancias',
			default                   => $type,
		};
	}

	/**
	 * WHERE y argumentos compartidos.
	 *
	 * @param array{user_id:int,type:string,from:string,to:string,order_id:int} $filters
	 * @return array{0:string,1:list<mixed>}
	 */
	private static function where( array $filters ): array {
		$parts = array( '1=1' );
		$args  = array();

		if ( 0 !== (int) $filters['user_id'] ) {
			$parts[] = 'l.user_id = %d';
			$args[]  = (int) $filters['user_id'];
		}
		if ( '' !== $filters['type'] ) {
			$parts[] = 'l.type = %s';
			$args[]  = $filters['type'];
		}
		if ( '' !== $filters['from'] ) {
			$parts[] = 'l.created_at >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
		}
		if ( '' !== $filters['to'] ) {
			$parts[] = 'l.created_at <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
		}
		if ( (int) $filters['order_id'] > 0 ) {
			$parts[] = "l.ref_type = 'order' AND l.ref_id = %s";
			$args[]  = (string) (int) $filters['order_id'];
		}

		return array( implode( ' AND ', $parts ), $args );
	}
}
