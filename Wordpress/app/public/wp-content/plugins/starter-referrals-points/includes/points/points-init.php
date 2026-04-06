<?php
/**
 * Inicialización del sistema de puntos
 * 
 * Funciones para inicializar y configurar el sistema de puntos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar el sistema de puntos
 */
function starter_rp_init_points() {
    // Programar verificación diaria de puntos expirados
    add_action('starter_rp_daily_maintenance', 'starter_rp_process_points_expiration');
    
    // Actualizar puntos al completar un pedido
    add_action('woocommerce_payment_complete', 'starter_rp_process_order_points');
    
    // Procesar puntos cuando el estado cambia a completado (manual o automático)
    add_action('woocommerce_order_status_completed', 'starter_rp_process_order_points', 10, 1);
    add_action('woocommerce_order_status_processing', 'starter_rp_check_and_process_order_points', 10, 1);
    
    // Añadir campo de puntos en la página de checkout
    add_action('woocommerce_review_order_before_payment', 'starter_rp_points_redemption_field');
    add_action('woocommerce_cart_calculate_fees', 'starter_rp_apply_points_discount');
    
    // Guardar puntos a usar en el pedido
    add_action('woocommerce_checkout_update_order_meta', 'starter_rp_save_order_points_used');
    
    // Asignar puntos cuando un usuario referido es aprobado
    add_action('starter_rp_referral_processed', 'starter_rp_assign_referral_signup_points', 10, 2);
    add_action('set_user_role', 'starter_rp_check_user_approval', 10, 3);
    
    // Inicializar eventos de puntos (registro, reseñas, cumpleaños)
    starter_rp_init_points_events();
}
