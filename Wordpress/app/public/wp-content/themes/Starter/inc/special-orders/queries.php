<?php
/**
 * Consultas de datos para Ventas Especiales
 * 
 * Funciones para obtener pedidos especiales, contadores y estadísticas
 * desde WooCommerce y las tablas personalizadas de compras.
 * 
 * @package Starter\SpecialOrders
 * @since 1.6.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consulta unificada de compras desde las tablas personalizadas
 * 
 * Fuente de verdad: tablas custom (starter_membership_purchases, starter_fc_purchases).
 * Soporta filtros por tipo, estado, búsqueda y paginación.
 * 
 * @param string $type     'all', STARTER_ORDER_TYPE_MEMBERSHIP, STARTER_ORDER_TYPE_VIRTUAL_COINS
 * @param string $status   'all', 'completed', 'pending', o cualquier status de la tabla custom
 * @param string $search   Término de búsqueda (referencia, ID de usuario, email)
 * @param int    $paged    Página actual
 * @param int    $per_page Resultados por página
 * @return array ['rows' => array, 'total' => int, 'pages' => int]
 */
function starter_so_query_purchases($type = 'all', $status = 'all', $search = '', $paged = 1, $per_page = null) {
    global $wpdb;
    
    if ($per_page === null) {
        $per_page = STARTER_SPECIAL_ORDERS_PER_PAGE;
    }
    
    $tables_to_query = starter_so_resolve_tables($type);
    
    if (empty($tables_to_query)) {
        return ['rows' => [], 'total' => 0, 'pages' => 0];
    }
    
    $union_parts = [];
    $count_parts = [];
    
    foreach ($tables_to_query as $tq) {
        $where_clauses = [];
        
        // Filtro por estado
        if ($status === 'pending_only') {
            // Todos los que NO están completados ni descartados
            $where_clauses[] = "status NOT IN ('completed', 'dismissed')";
        } elseif ($status !== 'all') {
            $where_clauses[] = $wpdb->prepare("status = %s", $status);
        }
        
        // Búsqueda
        if (!empty($search)) {
            $search = trim($search);
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_clauses[] = $wpdb->prepare(
                "(reference LIKE %s OR user_id = %d)",
                $like,
                absint($search)
            );
        }
        
        $where = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
        
        $union_parts[] = sprintf(
            "SELECT id, user_id, product_id, reference, price, status, wompi_transaction_id, NULL as wc_order_id, created_at, processed_at, '%s' as order_type FROM `%s` %s",
            $tq['order_type'],
            $tq['table'],
            $where
        );
        
        $count_parts[] = sprintf(
            "SELECT COUNT(*) FROM `%s` %s",
            $tq['table'],
            $where
        );
    }
    
    // Total
    $total = 0;
    foreach ($count_parts as $count_sql) {
        $total += (int) $wpdb->get_var($count_sql);
    }
    
    if ($total === 0) {
        return ['rows' => [], 'total' => 0, 'pages' => 0];
    }
    
    // Consulta paginada con UNION
    $union_sql = implode(' UNION ALL ', $union_parts);
    $offset = ($paged - 1) * $per_page;
    
    $sql = "SELECT * FROM ({$union_sql}) AS combined ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $per_page, $offset));
    
    // Enriquecer filas con datos de usuario, producto y datos específicos
    starter_so_enrich_rows($rows);
    
    return [
        'rows'  => $rows,
        'total' => $total,
        'pages' => (int) ceil($total / $per_page),
    ];
}

/**
 * Resolver qué tablas custom consultar según el tipo de filtro
 * 
 * @param string $type 'all', STARTER_ORDER_TYPE_MEMBERSHIP o STARTER_ORDER_TYPE_VIRTUAL_COINS
 * @return array Tablas a consultar con su order_type
 */
function starter_so_resolve_tables($type) {
    global $wpdb;
    
    $tables = [];
    
    if ($type === 'all' || $type === STARTER_ORDER_TYPE_MEMBERSHIP) {
        $table = $wpdb->prefix . STARTER_TABLE_MEMBERSHIP_PURCHASES;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $tables[] = [
                'table'      => $table,
                'order_type' => STARTER_ORDER_TYPE_MEMBERSHIP,
            ];
        }
    }
    
    if ($type === 'all' || $type === STARTER_ORDER_TYPE_VIRTUAL_COINS) {
        $table = $wpdb->prefix . STARTER_TABLE_FC_PURCHASES;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $tables[] = [
                'table'      => $table,
                'order_type' => STARTER_ORDER_TYPE_VIRTUAL_COINS,
            ];
        }
    }
    
    return $tables;
}

/**
 * Enriquecer filas con datos de usuario, producto y datos específicos del tipo
 * 
 * @param array &$rows Filas a enriquecer (por referencia)
 */
function starter_so_enrich_rows(&$rows) {
    global $wpdb;
    
    foreach ($rows as &$row) {
        // Datos de usuario
        $user = get_userdata($row->user_id);
        $row->customer_name  = $user ? trim($user->first_name . ' ' . $user->last_name) : '';
        $row->customer_email = $user ? $user->user_email : '';
        $row->product_name   = get_the_title($row->product_id) ?: 'Producto #' . $row->product_id;
        
        // Datos específicos de membresía
        if ($row->order_type === STARTER_ORDER_TYPE_MEMBERSHIP) {
            $mb_table = $wpdb->prefix . STARTER_TABLE_MEMBERSHIP_PURCHASES;
            $mb_data = $wpdb->get_row($wpdb->prepare(
                "SELECT membership_level, duration_days, monthly_points FROM `{$mb_table}` WHERE id = %d",
                $row->id
            ));
            $row->membership_level = $mb_data ? (int) $mb_data->membership_level : 0;
            $row->duration_days    = $mb_data ? (int) $mb_data->duration_days : 0;
            $row->monthly_points   = $mb_data ? (int) $mb_data->monthly_points : 0;
        }
        
        // Datos específicos de FC
        if ($row->order_type === STARTER_ORDER_TYPE_VIRTUAL_COINS) {
            $fc_table = $wpdb->prefix . STARTER_TABLE_FC_PURCHASES;
            $fc_data = $wpdb->get_row($wpdb->prepare(
                "SELECT total_coins FROM `{$fc_table}` WHERE id = %d",
                $row->id
            ));
            $row->total_coins = $fc_data ? (int) $fc_data->total_coins : 0;
        }
    }
    unset($row);
}

/**
 * Obtener contadores por tipo desde las tablas custom (fuente de verdad)
 * 
 * @return array ['all' => int, 'membership' => int, 'virtual_coins' => int]
 */
function starter_so_get_counts() {
    global $wpdb;
    
    $membership_count = 0;
    $fc_count = 0;
    
    $mb_table = $wpdb->prefix . STARTER_TABLE_MEMBERSHIP_PURCHASES;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $mb_table)) === $mb_table) {
        $membership_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$mb_table}` WHERE status = 'completed'");
    }
    
    $fc_table = $wpdb->prefix . STARTER_TABLE_FC_PURCHASES;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $fc_table)) === $fc_table) {
        $fc_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$fc_table}` WHERE status = 'completed'");
    }
    
    return [
        'all'          => $membership_count + $fc_count,
        'membership'   => $membership_count,
        'virtual_coins' => $fc_count,
    ];
}

/**
 * Wrapper: obtener solo compras pendientes (no completadas)
 * 
 * @param string $type   'all', STARTER_ORDER_TYPE_MEMBERSHIP o STARTER_ORDER_TYPE_VIRTUAL_COINS
 * @param string $search Término de búsqueda
 * @param int    $paged  Página actual
 * @return array ['rows' => array, 'total' => int, 'pages' => int]
 */
function starter_so_get_pending_purchases($type = 'all', $search = '', $paged = 1) {
    return starter_so_query_purchases($type, 'pending_only', $search, $paged);
}

/**
 * Obtener conteo de compras pendientes desde las tablas custom
 * 
 * @return int Total de compras no completadas
 */
function starter_so_get_pending_count() {
    global $wpdb;
    
    $count = 0;
    
    $tables = [
        $wpdb->prefix . STARTER_TABLE_MEMBERSHIP_PURCHASES,
        $wpdb->prefix . STARTER_TABLE_FC_PURCHASES,
    ];
    
    foreach ($tables as $table) {
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $count += (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$table}` WHERE status NOT IN ('completed', 'dismissed')"
            );
        }
    }
    
    return $count;
}

/**
 * Obtener estadísticas de resumen desde las tablas personalizadas de compras
 * 
 * Usa las tablas starter_membership_purchases y starter_fc_purchases
 * como fuente de verdad (más preciso que las órdenes WC).
 * 
 * @param string $type Tipo de filtro actual: 'all', 'membership_purchase', 'virtual_coins_purchase'
 * @return array ['membership' => object|null, 'virtual_coins' => object|null]
 */
function starter_so_get_summary_stats($type) {
    global $wpdb;
    
    $stats = [
        'membership'   => null,
        'virtual_coins' => null,
    ];
    
    // Stats de membresías
    if ($type === 'all' || $type === STARTER_ORDER_TYPE_MEMBERSHIP) {
        $table = $wpdb->prefix . STARTER_TABLE_MEMBERSHIP_PURCHASES;
        
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $stats['membership'] = $wpdb->get_row(
                "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'completed' THEN price ELSE 0 END) as total_revenue
                FROM `{$table}`"
            );
        }
    }
    
    // Stats de FC
    if ($type === 'all' || $type === STARTER_ORDER_TYPE_VIRTUAL_COINS) {
        $table = $wpdb->prefix . STARTER_TABLE_FC_PURCHASES;
        
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $stats['virtual_coins'] = $wpdb->get_row(
                "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'completed' THEN price ELSE 0 END) as total_revenue,
                    SUM(CASE WHEN status = 'completed' THEN total_coins ELSE 0 END) as total_coins_sold
                FROM `{$table}`"
            );
        }
    }
    
    return $stats;
}
