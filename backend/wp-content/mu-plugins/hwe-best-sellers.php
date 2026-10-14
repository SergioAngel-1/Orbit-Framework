<?php
/**
 * Plugin Name: HWE Best Sellers
 * Description: Ranking de productos más vendidos para el frontend (orden «Más
 *              vendidos», franja de la home). Endpoint interno: solo el BFF
 *              (X-HWE-Internal-Secret).
 *
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 *
 * ── La regla: el ranking sale de las líneas de pedido PAGADAS ──────────────
 * Se suman las unidades (`_qty`) de las líneas de los pedidos en `processing` o
 * `completed`, agrupadas por `_product_id`, solo de productos publicados. No se usa el
 * meta `total_sales` de WooCommerce: lo incrementa al cobrar pero no lo descuenta al
 * cancelar o reembolsar, y un catálogo importado o migrado lo trae a cero aunque el
 * historial de pedidos exista. Las líneas de pedido son la fuente que nunca miente.
 *
 * Además, `totalSales` de WooGraphQL es un campo restringido (llega `null` sin sesión de
 * gestor), y el volumen de ventas por producto no es un dato que deba ser público: de
 * ahí el secreto interno.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_BEST_SELLERS_TRANSIENT = 'hwe_best_sellers_v2';

add_action( 'rest_api_init', function () {
	register_rest_route( 'hwe/v1', '/best-sellers', [
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $request ): bool {
			return function_exists( 'hwe_has_internal_secret' ) && hwe_has_internal_secret( $request );
		},
		'callback'            => 'hwe_best_sellers_handle',
	] );
} );

// Un pedido que entra o sale de un estado pagado mueve el ranking. Borrar el
// transient es barato; el siguiente GET lo recalcula.
add_action( 'woocommerce_order_status_changed', function () {
	delete_transient( HWE_BEST_SELLERS_TRANSIENT );
} );

/**
 * Ranking completo: `[ [ 'id' => int, 'slug' => string, 'qty' => int ], ... ]` en orden
 * descendente de unidades vendidas, solo productos publicados y solo pedidos
 * pagados (`processing`, `completed`).
 */
function hwe_best_sellers_ranking(): array {
	$cached = get_transient( HWE_BEST_SELLERS_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$items    = $wpdb->prefix . 'woocommerce_order_items';
	$itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
	$statuses = [ 'wc-processing', 'wc-completed' ];

	$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

	if ( $hpos ) {
		$orders_join = "JOIN {$wpdb->prefix}wc_orders o ON o.id = oi.order_id
			AND o.type = 'shop_order' AND o.status IN (%s, %s)";
	} else {
		$orders_join = "JOIN {$wpdb->posts} o ON o.ID = oi.order_id
			AND o.post_type = 'shop_order' AND o.post_status IN (%s, %s)";
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla internos.
	$sql = "SELECT p.ID AS id, p.post_name AS slug, SUM(CAST(q.meta_value AS DECIMAL(12,2))) AS qty
		FROM {$items} oi
		{$orders_join}
		JOIN {$itemmeta} pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
		JOIN {$itemmeta} q ON q.order_item_id = oi.order_item_id AND q.meta_key = '_qty'
		JOIN {$wpdb->posts} p ON p.ID = CAST(pm.meta_value AS UNSIGNED)
			AND p.post_type = 'product' AND p.post_status = 'publish'
		WHERE oi.order_item_type = 'line_item'
		GROUP BY p.ID
		HAVING qty > 0
		ORDER BY qty DESC, p.post_date DESC";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$statuses ), ARRAY_A );

	$ranking = array_map(
		static fn( $row ) => [
			'id'   => (int) $row['id'],
			'slug' => (string) $row['slug'],
			'qty'  => (int) round( (float) $row['qty'] ),
		],
		is_array( $rows ) ? $rows : []
	);

	set_transient( HWE_BEST_SELLERS_TRANSIENT, $ranking, HOUR_IN_SECONDS );
	return $ranking;
}

function hwe_best_sellers_handle( WP_REST_Request $request ) {
	return new WP_REST_Response( [ 'items' => hwe_best_sellers_ranking() ], 200 );
}
