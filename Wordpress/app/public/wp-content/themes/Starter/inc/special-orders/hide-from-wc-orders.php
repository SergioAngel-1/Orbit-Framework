<?php
/**
 * Ocultar pedidos especiales de la lista principal de WooCommerce
 * 
 * Filtra los pedidos de membresías y paquetes de Virtual Coins
 * para que no aparezcan en la pantalla estándar de wc-orders.
 * 
 * Soporta tanto el sistema legacy (post type) como HPOS.
 * 
 * @package Starter\SpecialOrders
 * @since 1.6.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Filtrar pedidos en el sistema legacy (post type shop_order)
 * 
 * Usa meta_query con NOT EXISTS / NOT IN para excluir
 * pedidos que tengan _order_type de membresía o FC.
 */
add_action('pre_get_posts', 'starter_so_hide_from_legacy_list');
function starter_so_hide_from_legacy_list($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    if ($query->get('post_type') !== 'shop_order') {
        return;
    }
    
    // No filtrar en nuestra página personalizada
    if (isset($_GET['page']) && $_GET['page'] === STARTER_SPECIAL_ORDERS_PAGE_SLUG) {
        return;
    }
    
    $meta_query = $query->get('meta_query') ?: [];
    $meta_query[] = [
        'relation' => 'OR',
        [
            'key'     => STARTER_ORDER_TYPE_META_KEY,
            'compare' => 'NOT EXISTS',
        ],
        [
            'key'     => STARTER_ORDER_TYPE_META_KEY,
            'value'   => starter_get_special_order_types(),
            'compare' => 'NOT IN',
        ],
    ];
    
    $query->set('meta_query', $meta_query);
}

/**
 * Filtrar pedidos en HPOS (WooCommerce Orders List Table)
 * 
 * Estrategia: excluir por IDs con caché transitorio.
 * Más confiable que meta_query NOT EXISTS en el motor HPOS.
 */
add_filter('woocommerce_order_list_table_prepare_items_query_args', 'starter_so_hide_from_hpos_list');
function starter_so_hide_from_hpos_list($query_args) {
    // No filtrar en nuestra página personalizada
    if (isset($_GET['page']) && $_GET['page'] === STARTER_SPECIAL_ORDERS_PAGE_SLUG) {
        return $query_args;
    }
    
    $special_order_ids = starter_so_get_ids_cached();
    
    if (!empty($special_order_ids)) {
        if (!isset($query_args['exclude'])) {
            $query_args['exclude'] = [];
        }
        $query_args['exclude'] = array_merge($query_args['exclude'], $special_order_ids);
    }
    
    return $query_args;
}

/**
 * Obtener IDs de pedidos especiales con doble capa de caché:
 * - Estática (por request, evita consultas duplicadas en el mismo page load)
 * - Transitorio (entre requests, TTL configurable)
 * 
 * @return array IDs de pedidos de membresía y FC
 */
function starter_so_get_ids_cached() {
    static $cached_ids = null;
    
    if ($cached_ids !== null) {
        return $cached_ids;
    }
    
    $cached_ids = get_transient(STARTER_SPECIAL_ORDER_IDS_TRANSIENT);
    
    if ($cached_ids === false) {
        $cached_ids = wc_get_orders([
            'type'       => 'shop_order',
            'status'     => 'any',
            'limit'      => -1,
            'return'     => 'ids',
            'meta_query' => [
                [
                    'key'     => STARTER_ORDER_TYPE_META_KEY,
                    'value'   => starter_get_special_order_types(),
                    'compare' => 'IN',
                ],
            ],
        ]);
        
        set_transient(STARTER_SPECIAL_ORDER_IDS_TRANSIENT, $cached_ids, STARTER_SPECIAL_ORDERS_CACHE_TTL);
    }
    
    return $cached_ids;
}

/**
 * Invalidar todas las cachés de pedidos especiales
 * 
 * Se dispara cuando se completa una nueva compra de membresía o FC.
 */
add_action('starter_membership_purchase_completed', 'starter_so_invalidate_cache', 10, 0);
add_action('starter_fc_purchase_completed', 'starter_so_invalidate_cache', 10, 0);
function starter_so_invalidate_cache() {
    delete_transient(STARTER_SPECIAL_ORDER_IDS_TRANSIENT);
    delete_transient(STARTER_SPECIAL_ORDER_COUNTS_TRANSIENT);
}
