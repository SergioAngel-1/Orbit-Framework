<?php
/**
 * Constantes del módulo de Ventas Especiales
 * 
 * Centraliza los valores de tipo de orden y claves de meta
 * para evitar cadenas mágicas dispersas en el código.
 * 
 * @package Starter\SpecialOrders
 * @since 1.6.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Tipos de orden especial (valores del meta '_order_type')
define('STARTER_ORDER_TYPE_MEMBERSHIP',   'membership_purchase');
define('STARTER_ORDER_TYPE_VIRTUAL_COINS', 'virtual_coins_purchase');

// Todos los tipos especiales como array
function starter_get_special_order_types() {
    return [
        STARTER_ORDER_TYPE_MEMBERSHIP,
        STARTER_ORDER_TYPE_VIRTUAL_COINS,
    ];
}

// Clave de meta donde se almacena el tipo de orden
define('STARTER_ORDER_TYPE_META_KEY', '_order_type');

// Slug de la página de administración
define('STARTER_SPECIAL_ORDERS_PAGE_SLUG', 'starter-special-orders');

// Claves de transient para caché
define('STARTER_SPECIAL_ORDER_IDS_TRANSIENT', 'starter_special_order_ids');
define('STARTER_SPECIAL_ORDER_COUNTS_TRANSIENT', 'starter_special_order_counts');

// TTL de caché en segundos (5 minutos)
define('STARTER_SPECIAL_ORDERS_CACHE_TTL', 5 * MINUTE_IN_SECONDS);

// Vista especial: compras pendientes (desde tablas custom, no WC orders)
define('STARTER_SPECIAL_ORDERS_VIEW_PENDING', 'pending_purchases');

// Pedidos por página en la vista admin
define('STARTER_SPECIAL_ORDERS_PER_PAGE', 20);

// Tablas personalizadas de compras
define('STARTER_TABLE_MEMBERSHIP_PURCHASES', 'starter_membership_purchases');
define('STARTER_TABLE_FC_PURCHASES', 'starter_fc_purchases');
