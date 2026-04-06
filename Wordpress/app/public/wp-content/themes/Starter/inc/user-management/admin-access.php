<?php
/**
 * Control de acceso administrativo
 * 
 * Este archivo contiene las funciones relacionadas con el control de acceso
 * de usuarios a la sección administrativa.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bloquear el acceso a la administración para usuarios pendientes de aprobación
 */
function block_pending_users_admin_access() {
    if (!is_admin()) {
        return;
    }
    
    $current_user = wp_get_current_user();
    if (!$current_user->exists()) {
        return;
    }
    
    // Verificar si el usuario está pendiente de aprobación
    if (get_user_meta($current_user->ID, 'pending_approval', true)) {
        wp_redirect(home_url());
        exit;
    }
}
add_action('init', 'block_pending_users_admin_access');
