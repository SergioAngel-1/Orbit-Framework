<?php
/**
 * Rechazo de usuarios
 * 
 * Este archivo contiene las funciones relacionadas con el rechazo de usuarios
 * pendientes en el sistema.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Procesar el rechazo de usuario
 */
function process_user_rejection() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'reject') {
        return;
    }
    
    if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
        return;
    }
    
    $user_id = intval($_GET['user_id']);
    
    if (!current_user_can('edit_users')) {
        wp_die(__('No tienes permisos para realizar esta acción.'));
    }
    
    check_admin_referer("reject-user_{$user_id}");
    
    // Verificar si el usuario está pendiente de aprobación
    if (get_user_meta($user_id, 'pending_approval', true)) {
        // Enviar correo de rechazo al usuario usando la función centralizada
        if (function_exists('starter_send_account_rejected_email')) {
            $rejection_sent = starter_send_account_rejected_email($user_id);
            if (!$rejection_sent) {
                error_log("Advertencia: No se pudo enviar el correo de rechazo al usuario ID: $user_id");
            }
        } else {
            error_log("Error: Función starter_send_account_rejected_email no está disponible");
        }
        
        // Cambiar el estado del usuario a rechazado
        delete_user_meta($user_id, 'pending_approval');
        update_user_meta($user_id, 'rejected_status', true);
        
        // No eliminamos el meta '_user_previously_approved' para saber si ya fue aprobado antes
        // Esto asegura que no se den puntos por referidos si es aprobado después
        
        // Asignar rol de rechazado
        $user = new WP_User($user_id);
        $user->set_role('rejected');
        
        // Mensaje de éxito
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible"><p>Usuario marcado como rechazado correctamente.</p></div>';
        });
    }
    
    // Redireccionar de vuelta a la lista de usuarios
    wp_redirect(admin_url('users.php'));
    exit;
}
add_action('admin_init', 'process_user_rejection');
