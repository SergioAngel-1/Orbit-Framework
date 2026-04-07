<?php
/**
 * Virtual Coins Purchase DB — Tabla y helpers de base de datos
 * 
 * Responsabilidades:
 * - Crear/migrar la tabla starter_fc_purchases
 * - Helpers para obtener y actualizar registros de compra
 * 
 * @package Starter
 * @since 1.1.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nombre de la tabla de compras de Virtual Coins
 */
function starter_fc_purchase_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'starter_fc_purchases';
}

/**
 * Crear tabla para compras pendientes de Virtual Coins
 * 
 * Se ejecuta en 'init' para garantizar que la tabla exista.
 * Usa dbDelta para creación idempotente.
 */
add_action('init', 'starter_create_fc_purchases_table', 10);
function starter_create_fc_purchases_table() {
    global $wpdb;
    
    $table_name = starter_fc_purchase_table_name();
    
    // Verificar si la tabla ya existe
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        // Migración: Agregar columna wc_order_id si no existe (para tablas existentes)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'wc_order_id'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN wc_order_id bigint(20) DEFAULT NULL AFTER wompi_transaction_id");
            $wpdb->query("ALTER TABLE $table_name ADD INDEX wc_order_id (wc_order_id)");
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Starter FC] Columna wc_order_id agregada a tabla existente');
            }
        }
        return;
    }
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        product_id bigint(20) NOT NULL,
        reference varchar(100) NOT NULL,
        coins_amount int(11) NOT NULL DEFAULT 0,
        coins_bonus int(11) NOT NULL DEFAULT 0,
        total_coins int(11) NOT NULL DEFAULT 0,
        price decimal(10,2) NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'pending',
        wompi_transaction_id varchar(100) DEFAULT NULL,
        wc_order_id bigint(20) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        processed_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY reference (reference),
        KEY user_id (user_id),
        KEY status (status),
        KEY wc_order_id (wc_order_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter FC] Tabla de compras pendientes creada');
    }
}

// ─── DB Helpers ──────────────────────────────────────────────────────────────

/**
 * Obtener una compra por referencia (sin restricción de usuario)
 *
 * @param string $reference
 * @return object|null
 */
function starter_fc_purchase_get_by_reference($reference) {
    global $wpdb;
    $table = starter_fc_purchase_table_name();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE reference = %s",
        $reference
    ));
}

/**
 * Obtener una compra por referencia restringida al usuario actual
 *
 * @param string $reference
 * @param int    $user_id
 * @return object|null
 */
function starter_fc_purchase_get_by_reference_and_user($reference, $user_id) {
    global $wpdb;
    $table = starter_fc_purchase_table_name();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE reference = %s AND user_id = %d",
        $reference, $user_id
    ));
}

/**
 * Obtener una compra por referencia con bloqueo exclusivo (FOR UPDATE)
 * 
 * IMPORTANTE: Debe usarse dentro de una transacción SQL (START TRANSACTION).
 *
 * @param string $reference
 * @return object|null
 */
function starter_fc_purchase_get_for_update($reference) {
    global $wpdb;
    $table = starter_fc_purchase_table_name();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE reference = %s FOR UPDATE",
        $reference
    ));
}

/**
 * Actualizar campos de un registro de compra
 *
 * @param int   $purchase_id  ID del registro
 * @param array $data         Campos a actualizar
 * @return int|false
 */
function starter_fc_purchase_update($purchase_id, array $data) {
    global $wpdb;
    $table = starter_fc_purchase_table_name();

    return $wpdb->update($table, $data, ['id' => $purchase_id]);
}

/**
 * Marcar una compra con un estado de error
 *
 * @param int    $purchase_id
 * @param string $error_status  Ej: 'amount_mismatch', 'error_adding_points', 'error_no_points_function'
 * @param string $wompi_tx_id
 */
function starter_fc_purchase_mark_error($purchase_id, $error_status, $wompi_tx_id = '') {
    starter_fc_purchase_update($purchase_id, [
        'status'               => $error_status,
        'wompi_transaction_id' => $wompi_tx_id,
        'processed_at'         => current_time('mysql'),
    ]);
}
