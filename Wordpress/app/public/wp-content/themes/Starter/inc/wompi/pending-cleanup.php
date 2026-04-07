<?php
/**
 * Limpieza automática de registros pendientes abandonados
 * 
 * Cron diario que marca como 'expired' los registros pendientes
 * con más de 24 horas en las tablas de Wompi:
 * - starter_fc_purchases (Virtual Coins)
 * - starter_membership_purchases (Membresías)
 * - starter_pending_card_payments (Pagos con tarjeta en checkout)
 * 
 * También limpia registros stuck en 'processing' con más de 1 hora.
 * Estos pueden quedar huérfanos si el proceso PHP crashea después del
 * COMMIT pero antes de completar la lógica del processor.
 * 
 * Para pagos de checkout, también cancela las órdenes WC vinculadas
 * y actualiza el método de pago a "Wompi - Expirado".
 * 
 * Estos registros se generan cuando un usuario inicia un pago pero
 * no lo completa (cierra el widget, abandona la página, etc.).
 * 
 * @package Starter\Wompi
 * @since 1.6.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Programar cron diario de limpieza
 */
add_action('wp', function() {
    if (!wp_next_scheduled('starter_wompi_pending_cleanup')) {
        wp_schedule_event(time(), 'daily', 'starter_wompi_pending_cleanup');
    }
});

/**
 * Ejecutar limpieza de registros pendientes abandonados
 */
add_action('starter_wompi_pending_cleanup', 'starter_wompi_cleanup_stale_pending');

function starter_wompi_cleanup_stale_pending() {
    global $wpdb;
    
    // Umbral: registros pendientes con más de 24 horas
    $threshold = date('Y-m-d H:i:s', strtotime('-24 hours'));
    // Umbral para 'processing' stuck: 1 hora (el timeout normal del processor es 60s,
    // pero damos margen amplio para evitar interferir con procesos legítimos)
    $processing_threshold = date('Y-m-d H:i:s', strtotime('-1 hour'));
    
    $total_cleaned = 0;
    $total_processing_cleaned = 0;
    
    // ── 1. Virtual Coins purchases ──
    $fc_table = $wpdb->prefix . 'starter_fc_purchases';
    if ($wpdb->get_var("SHOW TABLES LIKE '$fc_table'") === $fc_table) {
        $count = $wpdb->query($wpdb->prepare(
            "UPDATE $fc_table SET status = 'expired', processed_at = %s WHERE status = 'pending' AND created_at < %s",
            current_time('mysql'), $threshold
        ));
        if ($count > 0) {
            $total_cleaned += $count;
            error_log(sprintf('[Starter Wompi Cleanup] %d compras de FC expiradas', $count));
        }
        // Limpiar registros stuck en 'processing' (crash del proceso PHP)
        $stuck = $wpdb->query($wpdb->prepare(
            "UPDATE $fc_table SET status = 'expired', processed_at = %s WHERE status = 'processing' AND created_at < %s",
            current_time('mysql'), $processing_threshold
        ));
        if ($stuck > 0) {
            $total_processing_cleaned += $stuck;
            error_log(sprintf('[Starter Wompi Cleanup] %d compras de FC stuck en processing limpiadas', $stuck));
        }
    }
    
    // ── 2. Membership purchases ──
    $mb_table = $wpdb->prefix . 'starter_membership_purchases';
    if ($wpdb->get_var("SHOW TABLES LIKE '$mb_table'") === $mb_table) {
        $count = $wpdb->query($wpdb->prepare(
            "UPDATE $mb_table SET status = 'expired', processed_at = %s WHERE status = 'pending' AND created_at < %s",
            current_time('mysql'), $threshold
        ));
        if ($count > 0) {
            $total_cleaned += $count;
            error_log(sprintf('[Starter Wompi Cleanup] %d compras de membresía expiradas', $count));
        }
        // Limpiar registros stuck en 'processing'
        $stuck = $wpdb->query($wpdb->prepare(
            "UPDATE $mb_table SET status = 'expired', processed_at = %s WHERE status = 'processing' AND created_at < %s",
            current_time('mysql'), $processing_threshold
        ));
        if ($stuck > 0) {
            $total_processing_cleaned += $stuck;
            error_log(sprintf('[Starter Wompi Cleanup] %d compras de membresía stuck en processing limpiadas', $stuck));
        }
    }
    
    // ── 3. Checkout card payments (con cancelación de órdenes WC) ──
    $cp_table = $wpdb->prefix . 'starter_pending_card_payments';
    if ($wpdb->get_var("SHOW TABLES LIKE '$cp_table'") === $cp_table) {
        // Obtener registros pendientes o stuck expirados ANTES de actualizarlos (para cancelar órdenes)
        $expired_payments = $wpdb->get_results($wpdb->prepare(
            "SELECT id, reference, order_id, status FROM $cp_table WHERE (status = 'pending' AND created_at < %s) OR (status = 'processing' AND created_at < %s)",
            $threshold, $processing_threshold
        ));
        
        if (!empty($expired_payments)) {
            // Marcar como expirados (tanto 'pending' >24h como 'processing' >1h)
            $count_pending = $wpdb->query($wpdb->prepare(
                "UPDATE $cp_table SET status = 'expired', processed_at = %s WHERE status = 'pending' AND created_at < %s",
                current_time('mysql'), $threshold
            ));
            $count_stuck = $wpdb->query($wpdb->prepare(
                "UPDATE $cp_table SET status = 'expired', processed_at = %s WHERE status = 'processing' AND created_at < %s",
                current_time('mysql'), $processing_threshold
            ));
            $count = $count_pending + $count_stuck;
            $total_cleaned += $count_pending;
            $total_processing_cleaned += $count_stuck;
            
            // Cancelar órdenes WC vinculadas
            $orders_cancelled = 0;
            foreach ($expired_payments as $payment) {
                if (!empty($payment->order_id)) {
                    $order = wc_get_order($payment->order_id);
                    if ($order && in_array($order->get_status(), ['wompi-verifying', 'pending', 'on-hold'])) {
                        $order->set_payment_method('wompi');
                        $order->set_payment_method_title('Wompi - Expirado');
                        delete_post_meta($payment->order_id, '_starter_card_payment_pending');
                        $order->set_status('cancelled', 'Pago expirado: sin confirmación de Wompi después de 24 horas.');
                        $order->save();
                        $orders_cancelled++;
                    }
                }
            }
            
            error_log(sprintf(
                '[Starter Wompi Cleanup] %d pagos de checkout expirados, %d órdenes WC canceladas',
                $count, $orders_cancelled
            ));
        }
    }
    
    if ($total_cleaned > 0 || $total_processing_cleaned > 0) {
        error_log(sprintf(
            '[Starter Wompi Cleanup] Limpieza completada: %d registros pending expirados, %d registros processing stuck limpiados',
            $total_cleaned, $total_processing_cleaned
        ));
    }
}

/**
 * Desregistrar cron al desactivar/cambiar tema
 */
add_action('switch_theme', function() {
    $timestamp = wp_next_scheduled('starter_wompi_pending_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'starter_wompi_pending_cleanup');
    }
});
