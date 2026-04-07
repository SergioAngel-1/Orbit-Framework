<?php
/**
 * Gestión de caché para el sistema de referidos y puntos
 *
 * Este archivo contiene funciones para invalidar automáticamente
 * el caché relacionado con referidos y puntos cuando ocurren cambios.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializa las funciones de gestión de caché para referidos
 * 
 * IMPORTANTE: Los hooks deben coincidir con las acciones reales disparadas en database.php:
 * - starter_rp_points_added    (user_id, points, type, description)
 * - starter_rp_points_deducted (user_id, points, type, description)
 * - starter_rp_points_used     (user_id, points, description)
 * - starter_rp_points_expired  (user_id, points_to_expire)
 */
function starter_rp_init_cache_management() {
    // Invalidar caché cuando se añaden puntos (earned, referral, etc.)
    add_action('starter_rp_points_added', 'starter_rp_invalidate_user_points_cache', 10, 3);
    
    // Invalidar caché cuando se deducen puntos (admin_deduct, etc.)
    add_action('starter_rp_points_deducted', 'starter_rp_invalidate_user_points_cache', 10, 3);
    
    // Invalidar caché cuando se usan puntos (checkout, transferencia)
    add_action('starter_rp_points_used', 'starter_rp_invalidate_user_points_cache_simple', 10, 2);
    
    // Invalidar caché cuando expiran puntos (cron diario)
    add_action('starter_rp_points_expired', 'starter_rp_invalidate_user_points_cache_simple', 10, 2);
    
    // Invalidar caché cuando se aprueba un usuario por primera vez
    add_action('starter_user_first_approval', 'starter_rp_invalidate_referrals_cache');
    
    // Invalidar caché cuando se agrega un nuevo referido
    add_action('starter_rp_after_add_referral', 'starter_rp_invalidate_referrals_cache');
    
    // Invalidar caché cuando se modifica cualquier dato de referidos o puntos
    add_action('starter_rp_data_modified', 'starter_rp_invalidate_all_referrals_cache');
}
add_action('init', 'starter_rp_init_cache_management');

/**
 * Invalidar el caché de puntos de un usuario (versión simplificada)
 * 
 * Para hooks que no pasan $type: starter_rp_points_used, starter_rp_points_expired
 * 
 * @param int $user_id ID del usuario
 * @param int $points Cantidad de puntos
 */
function starter_rp_invalidate_user_points_cache_simple($user_id, $points) {
    starter_rp_invalidate_user_points_cache($user_id, $points, 'unknown');
}

/**
 * Invalidar el caché de puntos de un usuario específico
 * 
 * @param int $user_id ID del usuario
 * @param int $points Cantidad de puntos
 * @param string $type Tipo de transacción
 */
function starter_rp_invalidate_user_points_cache($user_id, $points, $type) {
    // Solo proceder si existe la clase de caché
    if (!class_exists('API_Cache_Manager')) {
        return;
    }
    
    $cache_manager = call_user_func(['API_Cache_Manager', 'instance']);
    
    // Invalidar caché de puntos del usuario
    $cache_manager->invalidate('points', $user_id);
    
    // Invalidar caché de transacciones del usuario
    $cache_manager->invalidate('transactions', $user_id);
    
    // Invalidar caché por tipo de transacción
    $cache_manager->invalidate_by_type($type);
    
    // Log para depuración
    starter_rp_log("Caché de puntos invalidado para usuario ID: {$user_id}, tipo: {$type}");
}

/**
 * Invalidar el caché de referidos para un usuario específico
 * 
 * @param int $user_id ID del usuario
 */
function starter_rp_invalidate_referrals_cache($user_id) {
    // Solo proceder si existe la clase de caché
    if (!class_exists('API_Cache_Manager')) {
        return;
    }
    
    $cache_manager = call_user_func(['API_Cache_Manager', 'instance']);
    
    // Invalidar caché de referidos del usuario
    $cache_manager->invalidate('referrals', $user_id);
    
    // Invalidar caché de puntos del usuario
    $cache_manager->invalidate('points', $user_id);
    
    // Invalidar caché de transacciones del usuario
    $cache_manager->invalidate('transactions', $user_id);
    
    // Invalidar tipo de referral_signup
    $cache_manager->invalidate_by_type('referral_signup');
    
    // Invalidar tipo de referral
    $cache_manager->invalidate_by_type('referral');
    
    // Log para depuración
    starter_rp_log("Caché de referidos invalidado para usuario ID: {$user_id}");
}

/**
 * Invalidar todo el caché relacionado con referidos y puntos
 */
function starter_rp_invalidate_all_referrals_cache() {
    // Solo proceder si existe la clase de caché
    if (!class_exists('API_Cache_Manager')) {
        return;
    }
    
    $cache_manager = call_user_func(['API_Cache_Manager', 'instance']);
    
    // Invalidar todos los tipos de caché relacionados con referidos
    $cache_manager->invalidate_by_type('points');
    $cache_manager->invalidate_by_type('transactions');
    $cache_manager->invalidate_by_type('referrals');
    $cache_manager->invalidate_by_type('referral');
    $cache_manager->invalidate_by_type('referral_signup');
    $cache_manager->invalidate_by_type('earned');
    $cache_manager->invalidate_by_type('used');
    $cache_manager->invalidate_by_type('expired');
    $cache_manager->invalidate_by_type('admin_add');
    $cache_manager->invalidate_by_type('admin_deduct');
    
    // Log para depuración
    starter_rp_log("Todo el caché de referidos y puntos ha sido invalidado");
}
