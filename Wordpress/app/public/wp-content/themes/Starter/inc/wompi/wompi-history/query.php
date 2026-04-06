<?php
/**
 * Wompi History Query — Consultas de datos unificadas
 * 
 * Responsabilidades:
 * - Consulta UNION ALL paginada sobre las 3 tablas Wompi
 * - Enriquecimiento con datos de usuario
 * - Resumen rápido de conteos por tipo
 * 
 * @package Starter
 * @since 1.1.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Consultar transacciones unificadas de las 3 tablas Wompi
 *
 * @param array $args {
 *     @type string $status   Filtro de estado: all|pending|completed|processing|error|dismissed
 *     @type string $type     Filtro de tipo: all|membership|virtual_coins|card_payment
 *     @type string $search   Búsqueda por referencia, usuario o ID de transacción Wompi
 *     @type int    $paged    Página actual
 *     @type int    $per_page Registros por página
 * }
 * @return array ['rows' => array, 'total' => int]
 */
function starter_wompi_history_query($args = []) {
    global $wpdb;

    $defaults = [
        'status'   => 'all',
        'type'     => 'all',
        'search'   => '',
        'paged'    => 1,
        'per_page' => 30,
    ];
    $args = wp_parse_args($args, $defaults);

    $t_mem  = $wpdb->prefix . 'starter_membership_purchases';
    $t_fc   = $wpdb->prefix . 'starter_fc_purchases';
    $t_card = $wpdb->prefix . 'starter_pending_card_payments';

    // Verificar qué tablas existen
    $tables_exist = [];
    foreach (['membership' => $t_mem, 'virtual_coins' => $t_fc, 'card_payment' => $t_card] as $key => $table) {
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $tables_exist[$key] = $table;
        }
    }

    if (empty($tables_exist)) {
        return ['rows' => [], 'total' => 0];
    }

    // Construir sub-queries por tabla
    $unions = [];

    if (isset($tables_exist['membership']) && ($args['type'] === 'all' || $args['type'] === 'membership')) {
        $unions[] = "SELECT
                id,
                user_id,
                reference,
                price AS amount,
                NULL AS total_with_fee,
                status,
                wompi_transaction_id,
                wc_order_id,
                created_at,
                processed_at,
                'membership' AS tx_type
            FROM `{$tables_exist['membership']}`";
    }

    if (isset($tables_exist['virtual_coins']) && ($args['type'] === 'all' || $args['type'] === 'virtual_coins')) {
        $unions[] = "SELECT
                id,
                user_id,
                reference,
                price AS amount,
                NULL AS total_with_fee,
                status,
                wompi_transaction_id,
                wc_order_id,
                created_at,
                processed_at,
                'virtual_coins' AS tx_type
            FROM `{$tables_exist['virtual_coins']}`";
    }

    if (isset($tables_exist['card_payment']) && ($args['type'] === 'all' || $args['type'] === 'card_payment')) {
        $unions[] = "SELECT
                id,
                user_id,
                reference,
                order_total AS amount,
                total_with_fee,
                status,
                wompi_transaction_id,
                order_id AS wc_order_id,
                created_at,
                processed_at,
                'card_payment' AS tx_type
            FROM `{$tables_exist['card_payment']}`";
    }

    if (empty($unions)) {
        return ['rows' => [], 'total' => 0];
    }

    $union_sql = implode(" UNION ALL ", $unions);

    // WHERE
    $where = [];
    $prepare_values = [];

    if ($args['status'] !== 'all') {
        $where[] = "t.status = %s";
        $prepare_values[] = $args['status'];
    }

    if (!empty($args['search'])) {
        $like = '%' . $wpdb->esc_like($args['search']) . '%';
        $where[] = "(t.reference LIKE %s OR t.wompi_transaction_id LIKE %s OR t.user_id = %d)";
        $prepare_values[] = $like;
        $prepare_values[] = $like;
        $prepare_values[] = absint($args['search']);
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $count_sql = "SELECT COUNT(*) FROM ($union_sql) AS t $where_sql";
    if (!empty($prepare_values)) {
        $count_sql = $wpdb->prepare($count_sql, ...$prepare_values);
    }
    $total = (int) $wpdb->get_var($count_sql);

    // Paginated results
    $offset = max(0, ($args['paged'] - 1) * $args['per_page']);
    $data_sql = "SELECT t.* FROM ($union_sql) AS t $where_sql ORDER BY t.created_at DESC LIMIT %d OFFSET %d";
    $all_values = array_merge($prepare_values, [$args['per_page'], $offset]);
    $data_sql = $wpdb->prepare($data_sql, ...$all_values);

    $rows = $wpdb->get_results($data_sql);

    // Enriquecer con datos de usuario
    if (!empty($rows)) {
        $user_ids = array_unique(array_column($rows, 'user_id'));
        $users_map = [];
        foreach ($user_ids as $uid) {
            $u = get_userdata($uid);
            if ($u) {
                $users_map[$uid] = $u->display_name ?: $u->user_email;
            }
        }
        foreach ($rows as &$row) {
            $row->user_display = $users_map[$row->user_id] ?? "User #{$row->user_id}";
        }
        unset($row);
    }

    return ['rows' => $rows ?: [], 'total' => $total];
}

/**
 * Resumen rápido de transacciones por tipo
 *
 * @return array
 */
function starter_wompi_history_summary() {
    global $wpdb;

    $tables = [
        'membership'   => [
            'table' => $wpdb->prefix . 'starter_membership_purchases',
            'label' => 'Membresías',
            'color' => '#9b59b6',
        ],
        'virtual_coins' => [
            'table' => $wpdb->prefix . 'starter_fc_purchases',
            'label' => 'Virtual Coins',
            'color' => '#f39c12',
        ],
        'card_payment' => [
            'table' => $wpdb->prefix . 'starter_pending_card_payments',
            'label' => 'Pagos Tarjeta',
            'color' => '#3498db',
        ],
    ];

    $summary = [];
    foreach ($tables as $key => $info) {
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $info['table'])) !== $info['table']) {
            continue;
        }

        $total     = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$info['table']}`");
        $pending   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$info['table']}` WHERE status IN ('pending', 'processing')");
        $completed = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$info['table']}` WHERE status = 'completed'");

        $summary[] = [
            'label'     => $info['label'],
            'color'     => $info['color'],
            'count'     => $total,
            'pending'   => $pending,
            'completed' => $completed,
        ];
    }

    return $summary;
}
