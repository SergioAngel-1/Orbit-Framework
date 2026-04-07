<?php
/**
 * Gestión del estado de usuarios - CONSOLIDADO
 * 
 * Este archivo contiene TODA la funcionalidad relacionada con la gestión del estado
 * de los usuarios (aprobado, rechazado, pendiente) desde el perfil de usuario.
 * 
 * INCLUYE:
 * - Visualización del campo de estado en user-edit.php
 * - Procesamiento y guardado del estado
 * - Cambio de roles según estado
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mostrar campo de estado en el perfil de usuario
 * 
 * @param WP_User $user El objeto del usuario que se está editando
 */
function starter_show_user_status_field($user) {
    // Solo administradores pueden ver/editar este campo
    if (!current_user_can('edit_users')) {
        return;
    }
    
    // No mostrar este campo para el usuario actual (no puede cambiar su propio estado)
    if (get_current_user_id() === $user->ID) {
        return;
    }
    
    // Obtener el estado actual del usuario
    $pending = get_user_meta($user->ID, 'pending_approval', true);
    $rejected = get_user_meta($user->ID, 'rejected_status', true);
    
    // Determinar el estado actual
    $current_status = 'approved'; // Por defecto
    if ($pending) {
        $current_status = 'pending';
    } elseif ($rejected) {
        $current_status = 'rejected';
    }
    
    ?>
    <h2><?php _e('Estado del Usuario', 'starter'); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="user_status"><?php _e('Estado', 'starter'); ?></label></th>
            <td>
                <?php if ($current_status === 'pending'): ?>
                    <p><strong style="color: #d63638; font-size: 14px;">⏳ Pendiente de aprobación</strong></p>
                    <p class="description">
                        Este usuario está pendiente de aprobación. Utiliza los botones en la 
                        <a href="<?php echo admin_url('users.php?status=pending'); ?>">lista de usuarios</a> 
                        para aprobar o rechazar.
                    </p>
                <?php else: ?>
                    <select name="user_status" id="user_status" class="regular-text">
                        <option value="approved" <?php selected($current_status, 'approved'); ?>>✅ Aprobado</option>
                        <option value="rejected" <?php selected($current_status, 'rejected'); ?>>❌ Rechazado</option>
                    </select>
                    <p class="description">
                        Cambia el estado del usuario entre <strong>Aprobado</strong> (puede acceder normalmente) 
                        y <strong>Rechazado</strong> (no puede iniciar sesión).
                    </p>
                    <?php if ($current_status === 'rejected'): ?>
                        <p class="description" style="color: #d63638; margin-top: 5px;">
                            ⚠️ Este usuario está actualmente <strong>RECHAZADO</strong> y no puede acceder al sitio.
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    </table>
    <?php
}
// Registrar hooks para mostrar el campo (prioridad 10 - antes del guardado)
add_action('show_user_profile', 'starter_show_user_status_field', 10);
add_action('edit_user_profile', 'starter_show_user_status_field', 10);

/**
 * Guardar el estado del usuario cuando se actualiza el perfil
 */
function save_user_status_field($user_id) {
    // Verificar permisos
    if (!current_user_can('edit_users')) {
        return;
    }
    
    // No permitir cambiar el estado del usuario actual
    if (get_current_user_id() === $user_id) {
        return;
    }
    
    // Solo procesar si se ha enviado el campo de estado
    if (isset($_POST['user_status'])) {
        $new_status = sanitize_text_field($_POST['user_status']);
        
        // Comprobar el estado actual
        $pending = get_user_meta($user_id, 'pending_approval', true);
        $was_rejected = get_user_meta($user_id, 'rejected_status', true);
        $previously_approved = get_user_meta($user_id, '_user_previously_approved', true);
        
        // Determinar el estado actual
        $current_status = 'approved'; // Por defecto
        if ($pending) {
            $current_status = 'pending';
        } elseif ($was_rejected) {
            $current_status = 'rejected';
        }
        
        // Solo procesar si hay un cambio real de estado
        if ($current_status === $new_status) {
            // No hay cambio, no hacer nada
            return;
        }
        
        // Registrar para depuración
        error_log("Cambiando estado de usuario ID: {$user_id} de '{$current_status}' a '{$new_status}'");
        
        // Permitir cambios de estado incluso para usuarios pendientes
        if ($pending) {
            error_log("Usuario pendiente, se procederá a actualizar su estado desde el perfil");
            delete_user_meta($user_id, 'pending_approval');
        }
        
        // Obtener datos del usuario antes de modificar
        $user_before = get_userdata($user_id);
        $role_before = $user_before->roles[0] ?? 'sin-rol';
        error_log("Rol anterior: {$role_before}");
        
        // Procesar el cambio de estado
        if ($new_status === 'approved') {
            // Cambiar a estado aprobado
            delete_user_meta($user_id, 'rejected_status');
            error_log("Meta 'rejected_status' eliminado para usuario ID: {$user_id}");
            
            // Actualizar rol
            $user = new WP_User($user_id);
            $user->set_role('customer');
            error_log("Rol actualizado a 'customer' para usuario ID: {$user_id}");
            
            // Marcar como previamente aprobado si es la primera vez
            if (!$previously_approved) {
                update_user_meta($user_id, '_user_previously_approved', '1');
                error_log("Usuario marcado como previamente aprobado ID: {$user_id}");
                
                // Procesar puntos por referido SOLO si es la primera aprobación
                do_action('starter_user_first_approval', $user_id);
                error_log("Acción starter_user_first_approval disparada para usuario ID: {$user_id}");
            }
            
            // Mensaje de éxito
            add_action('user_profile_update_errors', function($errors) use ($was_rejected) {
                if ($was_rejected) {
                    $errors->add('success', 'El usuario ha sido cambiado de Rechazado a Aprobado.', 'updated');
                } else {
                    $errors->add('success', 'El usuario ha sido aprobado correctamente.', 'updated');
                }
            });
        } else if ($new_status === 'rejected') {
            // Cambiar a estado rechazado
            update_user_meta($user_id, 'rejected_status', true);
            error_log("Meta 'rejected_status' establecido para usuario ID: {$user_id}");
            
            // Actualizar rol (asegurándose de que el rol existe)
            if (!get_role('rejected')) {
                starter_create_rejected_role();
                error_log("Rol 'rejected' creado porque no existía");
            }
            
            $user = new WP_User($user_id);
            $user->set_role('rejected');
            error_log("Rol actualizado a 'rejected' para usuario ID: {$user_id}");
            
            // Mensaje de éxito
            add_action('user_profile_update_errors', function($errors) {
                $errors->add('success', 'El usuario ha sido marcado como rechazado correctamente.', 'updated');
            });
        }
        
        // Verificar cambio de rol
        $user_after = get_userdata($user_id);
        $role_after = $user_after->roles[0] ?? 'sin-rol';
        error_log("Rol después del cambio: {$role_after}");
    }
}
// Registrar en ambos hooks para personal_options_update (perfil propio) y edit_user_profile_update (perfil de otro)
// Usamos prioridad 20 para asegurarnos de que se ejecute después de las actualizaciones estándar de WordPress
add_action('personal_options_update', 'save_user_status_field', 20);
add_action('edit_user_profile_update', 'save_user_status_field', 20);
