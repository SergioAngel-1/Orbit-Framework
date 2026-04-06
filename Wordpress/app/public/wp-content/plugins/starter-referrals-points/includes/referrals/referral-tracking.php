<?php
/**
 * Seguimiento de referidos
 * 
 * Funciones para el seguimiento de referidos a través de URL y cookies.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rastrear referido a través de URL
 */
function starter_rp_track_referral() {
    // Verificar si el usuario está logeado
    if (is_user_logged_in()) {
        return;
    }
    
    // Verificar si hay código de referido en la URL
    $ref = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : '';
    
    if (empty($ref)) {
        return;
    }
    
    // Verificar que el código exista
    global $wpdb;
    $table = $wpdb->prefix . 'starter_referrals';
    $exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $table WHERE referral_code = %s
    ", $ref));
    
    if ($exists > 0) {
        // Guardar en cookie por 30 días
        setcookie('starter_referral', $ref, time() + (86400 * 30), '/');
        
        // Log para depuración
        starter_rp_log("Cookie de referido establecida: $ref");
    }
}

/**
 * Obtener referido por código
 * 
 * @param string $code Código de referido
 * @return array|false Datos del referido o false si no se encuentra
 */
function starter_rp_get_referral_by_code($code) {
    global $wpdb;
    
    if (empty($code)) {
        return false;
    }
    
    $table = $wpdb->prefix . 'starter_referrals';
    
    $referral = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM $table WHERE referral_code = %s
    ", $code), ARRAY_A);
    
    if (!$referral) {
        return false;
    }
    
    // Añadir información del usuario
    $user = get_userdata($referral['user_id']);
    if ($user) {
        $referral['user_data'] = [
            'display_name' => $user->display_name,
            'user_email' => $user->user_email,
            'user_registered' => $user->user_registered
        ];
    }
    
    return $referral;
}
