<?php
/**
 * Integración con el sistema de aprobación de usuarios
 * 
 * Este archivo maneja la integración entre el sistema de aprobación/rechazo de usuarios
 * y el sistema de puntos por referidos.
 */

if (!defined('ABSPATH')) {
    exit; // Acceso directo no permitido
}

/**
 * Procesar puntos por referidos cuando un usuario es aprobado por primera vez
 * y generar su código de referido único
 */
function starter_process_referral_points_on_first_approval($user_id) {
    global $wpdb;
    
    // Verificar si el sistema de referidos está habilitado
    if (!starter_rp_is_referrals_system_enabled()) {
        starter_rp_log_access_denied('process_referral_points_on_first_approval', $user_id, 'Sistema de referidos deshabilitado');
        return;
    }
    
    // NOTA: NO verificamos starter_rp_can_user_use_referrals para el usuario aprobado
    // porque un usuario recién aprobado puede tener nivel 0 (Zanahoria) y no pasaría la validación.
    // Solo verificamos que el usuario puede participar según su rol.
    // La validación de membresía nivel 1+ se hace para el REFERIDOR cuando se asignan puntos.
    if (!starter_rp_can_user_participate($user_id)) {
        starter_rp_log_access_denied('process_referral_points_on_first_approval', $user_id, 'Rol no permitido para referidos');
        return;
    }
    
    // Registrar información
    starter_rp_log("Procesando puntos por referidos para usuario ID: {$user_id} (primera aprobación)");
    
    // Asegurar que el usuario aprobado tenga un código de referido
    // starter_rp_generate_referral_code retorna el existente si ya tiene uno,
    // o genera uno nuevo si no lo tiene (también lo inserta en la tabla)
    $user_data = get_userdata($user_id);
    if ($user_data) {
        $referral_code = starter_rp_generate_referral_code($user_id);
        starter_rp_log("Código de referido para usuario aprobado ID {$user_id}: {$referral_code}");
    }
    
    // Verificar si existe el meta de referidor pendiente
    $referrer_id = get_user_meta($user_id, '_starter_pending_referral_points', true);
    
    if (!$referrer_id) {
        starter_rp_log("No hay referidor pendiente para el usuario ID: {$user_id}");
        return;
    }
    
    // Obtener configuración de puntos
    $options = Starter_RP()->get_options();
    $signup_points = isset($options['referral_signup_points']) ? intval($options['referral_signup_points']) : 100;
    
    // Verificar que el referidor exista y esté activo
    $referrer_data = get_userdata($referrer_id);
    if (!$referrer_data) {
        starter_rp_log("El referidor ID: {$referrer_id} no existe o fue eliminado");
        delete_user_meta($user_id, '_starter_pending_referral_points');
        return;
    }
    
    // Verificar que el referidor puede participar en el sistema de referidos
    if (!starter_rp_can_user_use_referrals($referrer_id)) {
        starter_rp_log("El referidor ID: {$referrer_id} no puede participar en el sistema de referidos");
        delete_user_meta($user_id, '_starter_pending_referral_points');
        return;
    }
    
    // Asegurarse de que la relación de referidos esté correctamente guardada en la base de datos
    $referrals_table = $wpdb->prefix . 'starter_referrals';
    
    // Verificar si el usuario ya tiene un registro en la tabla de referidos
    $user_exists = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $referrals_table WHERE user_id = %d
    ", $user_id));
    
    if ($user_exists) {
        // Actualizar el registro del usuario con su referidor
        $result = $wpdb->update(
            $referrals_table,
            ['referrer_id' => $referrer_id],
            ['user_id' => $user_id]
        );
        
        starter_rp_log("Actualizando relación de referido: Usuario ID {$user_id}, Referidor ID {$referrer_id}, Resultado: " . ($result !== false ? 'Éxito' : 'Error'));
    } else {
        // Si no existe, crear un registro nuevo con el código de referido ya generado
        $code = starter_rp_get_user_referral_code($user_id);
        if (!$code) {
            // Si por alguna razón no se generó antes, crear uno
            $code = substr(md5($user_id . time()), 0, 8);
            starter_rp_log("Generando código de respaldo para usuario ID {$user_id}: {$code}");
        }
        
        $wpdb->insert(
            $referrals_table,
            [
                'user_id' => $user_id,
                'referrer_id' => $referrer_id,
                'referral_code' => $code,
                'signup_date' => current_time('mysql')
            ]
        );
        starter_rp_log("Creando nuevo registro de referido: Usuario ID {$user_id}, Referidor ID {$referrer_id}, Código {$code}");
    }
    
    if ($signup_points > 0) {
        // Obtener información del usuario referido
        $user = get_userdata($user_id);
        $user_name = $user ? $user->display_name : "Usuario #{$user_id}";
        
        // Descripción para la transacción
        $description = sprintf('Puntos por nuevo referido: %s', $user_name);
        
        // Añadir puntos al referidor
        starter_rp_add_points(
            $referrer_id,
            $signup_points,
            'referral_signup',
            $description,
            $user_id,
            $options['points_expiration_days']
        );
        
        // Marcar como procesado para evitar duplicados
        delete_user_meta($user_id, '_starter_pending_referral_points');
        
        // Invalidar el caché de referidos y puntos
        do_action('starter_rp_data_modified');
        
        // Registrar en el log
        starter_rp_log(sprintf('Puntos por referido asignados: %d puntos para usuario #%d por referir a #%d', 
            $signup_points, $referrer_id, $user_id));
    }
}
add_action('starter_user_first_approval', 'starter_process_referral_points_on_first_approval');

/**
 * Evitar la asignación automática de puntos por cambio de rol
 * 
 * Esta función desactiva el hook existente que asigna puntos
 * cuando un usuario cambia de rol, ya que ahora usamos nuestro
 * propio sistema basado en la aprobación manual.
 */
function starter_disable_automatic_role_points() {
    // Si existe la función starter_rp_check_user_approval, la desconectamos
    if (function_exists('starter_rp_check_user_approval')) {
        remove_action('set_user_role', 'starter_rp_check_user_approval', 10);
    }
}
add_action('init', 'starter_disable_automatic_role_points', 20);
