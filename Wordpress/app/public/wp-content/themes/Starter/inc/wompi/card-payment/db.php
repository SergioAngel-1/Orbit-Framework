<?php
/**
 * Card Payment DB — Tabla y helpers de base de datos
 * 
 * Responsabilidades:
 * - Crear/migrar la tabla starter_pending_card_payments
 * - Helpers para obtener y actualizar registros de pago
 * 
 * @package Starter
 * @since 1.1.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nombre de la tabla de pagos pendientes con tarjeta
 */
function starter_card_payment_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'starter_pending_card_payments';
}

/**
 * Crear tabla para pagos pendientes con tarjeta
 * 
 * Se ejecuta en 'init' para garantizar que la tabla exista.
 * Usa dbDelta para creación idempotente (no duplica si ya existe).
 */
add_action('init', 'starter_create_pending_card_payments_table', 10);
function starter_create_pending_card_payments_table() {
    global $wpdb;
    $table_name = starter_card_payment_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    // Verificar si la tabla ya existe
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        return;
    }

    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        reference varchar(100) NOT NULL,
        order_id bigint(20) unsigned DEFAULT NULL,
        order_total decimal(10,2) NOT NULL,
        fee_percentage decimal(5,2) NOT NULL DEFAULT 5.00,
        fee_amount decimal(10,2) NOT NULL,
        total_with_fee decimal(10,2) NOT NULL,
        fc_for_order bigint(20) NOT NULL DEFAULT 0,
        order_data longtext DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        wompi_transaction_id varchar(100) DEFAULT NULL,
        fc_purchase_transaction_id bigint(20) unsigned DEFAULT NULL,
        fc_payment_transaction_id bigint(20) unsigned DEFAULT NULL,
        created_at datetime NOT NULL,
        processed_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY reference (reference),
        KEY user_id (user_id),
        KEY status (status)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter Card Payment] Tabla de pagos pendientes creada');
    }
}

/**
 * Migrar tabla existente: agregar columna order_data si no existe
 */
add_action('init', 'starter_card_payment_migrate_order_data_column', 11);
function starter_card_payment_migrate_order_data_column() {
    // Verificar si ya se migró (evita SHOW COLUMNS en cada request)
    if (get_option('starter_card_payment_has_order_data_col', false)) {
        return;
    }

    global $wpdb;
    $table_name = starter_card_payment_table_name();

    // Solo migrar si la tabla existe pero la columna no
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
        return;
    }

    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM `$table_name` LIKE 'order_data'");
    if (!empty($column_exists)) {
        update_option('starter_card_payment_has_order_data_col', true);
        return;
    }

    $wpdb->query("ALTER TABLE `$table_name` ADD COLUMN `order_data` longtext DEFAULT NULL AFTER `fc_for_order`");
    update_option('starter_card_payment_has_order_data_col', true);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[Starter Card Payment] Migración: columna order_data agregada');
    }
}

// ─── DB Helpers ──────────────────────────────────────────────────────────────

/**
 * Obtener un pago por referencia (sin restricción de usuario)
 *
 * @param string $reference
 * @return object|null
 */
function starter_card_payment_get_by_reference($reference) {
    global $wpdb;
    $table = starter_card_payment_table_name();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE reference = %s",
        $reference
    ));
}

/**
 * Obtener un pago por referencia restringido al usuario actual
 *
 * @param string $reference
 * @param int    $user_id
 * @return object|null
 */
function starter_card_payment_get_by_reference_and_user($reference, $user_id) {
    global $wpdb;
    $table = starter_card_payment_table_name();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE reference = %s AND user_id = %d",
        $reference, $user_id
    ));
}

/**
 * Obtener un pago por referencia con bloqueo exclusivo (FOR UPDATE)
 * 
 * IMPORTANTE: Debe usarse dentro de una transacción SQL (START TRANSACTION).
 *
 * @param string $reference
 * @return object|null
 */
function starter_card_payment_get_for_update($reference) {
    global $wpdb;
    $table = starter_card_payment_table_name();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE reference = %s FOR UPDATE",
        $reference
    ));
}

/**
 * Actualizar campos de un registro de pago
 *
 * @param int   $payment_id  ID del registro
 * @param array $data        Campos a actualizar (columna => valor)
 * @return int|false Número de filas afectadas o false en error
 */
function starter_card_payment_update($payment_id, array $data) {
    global $wpdb;
    $table = starter_card_payment_table_name();

    return $wpdb->update($table, $data, ['id' => $payment_id]);
}

/**
 * Marcar un pago con un estado de error y guardar datos de la transacción
 *
 * @param int    $payment_id
 * @param string $error_status  Ej: 'error', 'amount_mismatch', 'error_no_points_function'
 * @param string $wompi_tx_id   ID de transacción de Wompi (puede ser vacío)
 * @param array  $extra_data    Campos adicionales a guardar
 */
function starter_card_payment_mark_error($payment_id, $error_status, $wompi_tx_id = '', $extra_data = []) {
    $data = array_merge([
        'status'               => $error_status,
        'wompi_transaction_id' => $wompi_tx_id,
        'processed_at'         => current_time('mysql'),
    ], $extra_data);

    starter_card_payment_update($payment_id, $data);
}

/**
 * Sincronizar metadatos de FC en la orden WC después de procesar el pago
 *
 * @param int    $order_id
 * @param int    $fc_for_order
 * @param int    $fc_purchase_tx_id
 * @param int    $fc_payment_tx_id
 * @param string $wompi_tx_id
 */
function starter_card_payment_sync_order_meta($order_id, $fc_for_order, $fc_purchase_tx_id, $fc_payment_tx_id, $wompi_tx_id) {
    // Marcar las transacciones FC como procesadas
    update_post_meta($order_id, '_starter_fc_transactions_processed', 'yes');
    update_post_meta($order_id, '_starter_fc_purchase_transaction_id', $fc_purchase_tx_id);
    update_post_meta($order_id, '_starter_fc_payment_transaction_id', $fc_payment_tx_id);
    update_post_meta($order_id, '_starter_fc_amount', $fc_for_order);
    // Ya no está pendiente de procesamiento
    delete_post_meta($order_id, '_starter_card_payment_pending');

    // Actualizar la orden WC
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $order->add_order_note(sprintf(
        'Transacciones de Virtual Coins (pago con tarjeta confirmado): %s FC comprados y utilizados como aporte.',
        number_format($fc_for_order)
    ));

    $order->set_payment_method('wompi');
    $order->set_payment_method_title('Wompi - PAGADO');
    $order->set_transaction_id($wompi_tx_id);

    // Si la orden estaba en estado "wompi-verifying" (pago pendiente PSE/Nequi),
    // moverla a "processing" ahora que el pago fue confirmado
    if ($order->get_status() === 'wompi-verifying') {
        $order->set_status('processing', 'Pago confirmado por Wompi. Transacción: ' . $wompi_tx_id);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter Card Payment] Orden #%d actualizada de wompi-verifying a processing',
                $order_id
            ));
        }
    }

    $order->save();
}
