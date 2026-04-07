<?php
/**
 * Aprobación de usuarios
 * 
 * Este archivo contiene las funciones relacionadas con la aprobación de usuarios
 * pendientes en el sistema.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Procesar la aprobación de usuario
 */
function process_user_approval() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'approve') {
        return;
    }
    
    if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
        return;
    }
    
    $user_id = intval($_GET['user_id']);
    
    if (!current_user_can('edit_users')) {
        wp_die(__('No tienes permisos para realizar esta acción.'));
    }
    
    check_admin_referer("approve-user_{$user_id}");
    
    // Verificar si el usuario está pendiente de aprobación
    if (get_user_meta($user_id, 'pending_approval', true)) {
        // Verificar si el usuario fue previamente aprobado
        $previously_approved = get_user_meta($user_id, '_user_previously_approved', true);
        $is_rejected = get_user_meta($user_id, 'rejected_status', true);
        
        // Marcar como aprobado
        delete_user_meta($user_id, 'pending_approval');
        
        // Si estaba rechazado, eliminar ese estado
        if ($is_rejected) {
            delete_user_meta($user_id, 'rejected_status');
        }
        
        // Cambiar rol de usuario a customer
        $user = new WP_User($user_id);
        $user->set_role('customer');
        
        // Marcar como previamente aprobado (esto evitará volver a dar puntos)
        if (!$previously_approved) {
            update_user_meta($user_id, '_user_previously_approved', '1');
            error_log("Primera aprobación para el usuario ID: {$user_id}");
            
            // Procesar puntos por referido SOLO si es la primera aprobación
            do_action('starter_user_first_approval', $user_id);
        } else {
            error_log("El usuario ID: {$user_id} ya había sido aprobado previamente. No se asignan puntos.");
        }
        
        // Enviar correo de aprobación al usuario usando la función centralizada
        $email_sent = false;
        $email_error = '';
        
        if (function_exists('starter_send_account_approved_email')) {
            $approval_sent = starter_send_account_approved_email($user_id);
            
            if (!$approval_sent) {
                $email_error = "No se pudo enviar el correo de aprobación al usuario ID: $user_id";
                error_log("Advertencia: " . $email_error);
                
                // Intentar obtener más detalles del usuario para el log
                $user_data = get_userdata($user_id);
                if ($user_data) {
                    error_log("Email del usuario: " . $user_data->user_email);
                }
            } else {
                $email_sent = true;
                error_log("Correo de aprobación enviado exitosamente al usuario ID: $user_id");
            }
        } else {
            $email_error = "Función starter_send_account_approved_email no está disponible";
            error_log("Error: " . $email_error);
        }
        
        // Mensaje de éxito con advertencia si el email falló
        add_action('admin_notices', function() use ($email_sent, $email_error) {
            if ($email_sent) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Usuario aprobado correctamente.</strong> Se ha enviado un correo de notificación al usuario.</p></div>';
            } else {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>Usuario aprobado correctamente.</strong></p>';
                echo '<p><strong>Advertencia:</strong> No se pudo enviar el correo de notificación. ' . esc_html($email_error) . '</p>';
                echo '<p>Por favor, notifica manualmente al usuario sobre la aprobación de su cuenta.</p>';
                echo '</div>';
            }
        });
    }
    
    // Redireccionar de vuelta a la lista de usuarios
    wp_redirect(admin_url('users.php'));
    exit;
}
add_action('admin_init', 'process_user_approval');
