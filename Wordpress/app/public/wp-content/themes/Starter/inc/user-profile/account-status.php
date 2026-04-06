<?php
/**
 * Estado de la cuenta de usuario
 * 
 * Este archivo contiene las funciones relacionadas con la verificación
 * y gestión del estado de la cuenta de usuario.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verificar si la cuenta del usuario está activa antes de permitir el login
 */
function check_user_account_status($user, $username, $password) {
    // Si ya hay un error, no hacer nada
    if (is_wp_error($user)) {
        return $user;
    }
    
    // Verificar si la cuenta está desactivada
    $account_active = get_user_meta($user->ID, 'account_active', true);
    
    // Si account_active es false (explícitamente), bloquear el acceso
    if ($account_active === '0' || $account_active === false) {
        return new WP_Error(
            'account_inactive',
            'Tu cuenta ha sido desactivada. Por favor, contacta al administrador.'
        );
    }
    
    return $user;
}
add_filter('authenticate', 'check_user_account_status', 30, 3);

/**
 * Establecer cuenta como activa por defecto para nuevos usuarios
 */
function set_default_account_status($user_id) {
    update_user_meta($user_id, 'account_active', true);
}
add_action('user_register', 'set_default_account_status');
