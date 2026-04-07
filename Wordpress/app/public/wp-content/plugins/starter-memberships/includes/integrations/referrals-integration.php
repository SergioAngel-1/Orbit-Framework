<?php
/**
 * Integración con el sistema de referidos
 * 
 * Asigna membresía Plata (nivel 2) por 1 periodo cuando un usuario
 * se registra con un código de referido válido.
 * 
 * @package Starter_Memberships
 * @subpackage Integrations
 * @since 1.4.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar integración con referidos
 */
function starter_memberships_init_referrals_integration() {
    // Hook cuando se procesa una relación de referido válida
    add_action('starter_rp_referral_processed', 'starter_memberships_grant_referral_membership', 10, 2);
    
    // Hook alternativo: cuando el usuario es aprobado (si hay aprobación manual)
    add_action('starter_user_first_approval', 'starter_memberships_check_pending_referral_membership', 10, 1);
}
add_action('init', 'starter_memberships_init_referrals_integration');

/**
 * Otorgar membresía Plata por registro con código de referido
 * 
 * Se ejecuta cuando se procesa exitosamente una relación de referido.
 * 
 * @param int $referrer_id ID del usuario que refirió
 * @param int $referred_id ID del usuario referido (nuevo usuario)
 */
function starter_memberships_grant_referral_membership($referrer_id, $referred_id) {
    // Verificar que la función de activación existe
    if (!function_exists('starter_activate_user_membership')) {
        error_log('Starter Memberships: Función starter_activate_user_membership no disponible');
        return;
    }
    
    // Verificar que el usuario referido existe
    $user = get_userdata($referred_id);
    if (!$user) {
        error_log("Starter Memberships: Usuario referido $referred_id no existe");
        return;
    }
    
    // Verificar que el referidor existe
    $referrer = get_userdata($referrer_id);
    if (!$referrer) {
        error_log("Starter Memberships: Referidor $referrer_id no existe");
        return;
    }
    
    // Obtener nivel de membresía del referidor
    $referrer_level = starter_get_user_membership_level($referrer_id);
    
    // Verificar si el beneficio referral_membership_bonus está habilitado para el nivel del referidor
    $referrer_benefits = Starter_Benefits_Service::get_level_benefits($referrer_level);
    $bonus_config = $referrer_benefits['referral_membership_bonus'] ?? ['enabled' => false];
    
    if (empty($bonus_config['enabled'])) {
        error_log(sprintf(
            'Starter Memberships: Referidor %d (nivel %d) no tiene habilitado el beneficio de membresía para referidos',
            $referrer_id, $referrer_level
        ));
        return;
    }
    
    // Obtener configuración del beneficio (nivel y días a otorgar)
    $referral_membership_level = intval($bonus_config['membership_level'] ?? 2);
    $referral_membership_days = intval($bonus_config['duration_days'] ?? 30);
    
    // Aplicar filtros para permitir personalización
    $referral_membership_level = apply_filters('starter_referral_membership_level', $referral_membership_level, $referrer_id, $referrer_level);
    $referral_membership_days = apply_filters('starter_referral_membership_days', $referral_membership_days, $referrer_id, $referrer_level);
    
    error_log(sprintf(
        'Starter Memberships: Referidor %d (nivel %d) puede otorgar membresía nivel %d por %d días a referido %d',
        $referrer_id, $referrer_level, $referral_membership_level, $referral_membership_days, $referred_id
    ));
    
    // Verificar si el usuario ya tiene una membresía activa de nivel >= al que se va a otorgar
    $current_level = starter_get_user_membership_level($referred_id);
    if ($current_level >= $referral_membership_level) {
        error_log("Starter Memberships: Usuario $referred_id ya tiene membresía nivel $current_level, no se asigna nivel $referral_membership_level por referido");
        return;
    }
    
    // Verificar si ya se otorgó membresía por referido anteriormente
    $already_granted = get_user_meta($referred_id, '_starter_referral_membership_granted', true);
    if ($already_granted) {
        error_log("Starter Memberships: Usuario $referred_id ya recibió membresía por referido anteriormente");
        return;
    }
    
    // Verificar si el usuario está pendiente de aprobación
    $pending_approval = get_user_meta($referred_id, 'pending_approval', true);
    
    if ($pending_approval) {
        // Usuario pendiente: guardar para asignar cuando sea aprobado
        update_user_meta($referred_id, '_starter_pending_referral_membership', [
            'level' => $referral_membership_level,
            'days' => $referral_membership_days,
            'referrer_id' => $referrer_id,
            'created_at' => current_time('mysql')
        ]);
        
        error_log(sprintf(
            'Starter Memberships: Membresía Plata pendiente guardada para usuario %d (referido por %d) - Se asignará al aprobar',
            $referred_id, $referrer_id
        ));
        
        return;
    }
    
    // Usuario ya aprobado: asignar membresía inmediatamente
    starter_memberships_activate_referral_membership($referred_id, $referral_membership_level, $referral_membership_days, $referrer_id);
}

/**
 * Verificar y asignar membresía pendiente por referido cuando el usuario es aprobado
 * 
 * @param int $user_id ID del usuario aprobado
 */
function starter_memberships_check_pending_referral_membership($user_id) {
    // Verificar si hay membresía pendiente por referido
    $pending = get_user_meta($user_id, '_starter_pending_referral_membership', true);
    
    if (empty($pending)) {
        return;
    }
    
    $level = isset($pending['level']) ? intval($pending['level']) : 2;
    $days = isset($pending['days']) ? intval($pending['days']) : 90;
    $referrer_id = isset($pending['referrer_id']) ? intval($pending['referrer_id']) : 0;
    
    // Verificar que el referidor aún tiene el beneficio habilitado
    // El referidor podría haber perdido su membresía desde que se guardó la pendiente
    if ($referrer_id > 0) {
        $referrer_level = starter_get_user_membership_level($referrer_id);
        $referrer_benefits = Starter_Benefits_Service::get_level_benefits($referrer_level);
        $bonus_config = $referrer_benefits['referral_membership_bonus'] ?? ['enabled' => false];
        
        if (empty($bonus_config['enabled'])) {
            error_log(sprintf(
                'Starter Memberships: Referidor %d (nivel %d) ya no tiene habilitado el beneficio de membresía para referidos. No se otorga bonus al usuario %d',
                $referrer_id, $referrer_level, $user_id
            ));
            // Limpiar meta de pendiente aunque no se asigne
            delete_user_meta($user_id, '_starter_pending_referral_membership');
            return;
        }
    }
    
    // Asignar membresía
    starter_memberships_activate_referral_membership($user_id, $level, $days, $referrer_id);
    
    // Limpiar meta de pendiente
    delete_user_meta($user_id, '_starter_pending_referral_membership');
}

/**
 * Activar membresía por referido
 * 
 * IMPORTANTE: Las membresías por bono de referido NO otorgan Virtual Coins.
 * Se marca con 'is_referral_bonus' = true para excluirlas del otorgamiento mensual.
 * 
 * @param int $user_id ID del usuario
 * @param int $level Nivel de membresía
 * @param int $days Duración en días
 * @param int $referrer_id ID del referidor
 */
function starter_memberships_activate_referral_membership($user_id, $level, $days, $referrer_id) {
    // Verificar que el usuario no tenga ya una membresía igual o superior
    $current_level = starter_get_user_membership_level($user_id);
    if ($current_level >= $level) {
        error_log("Starter Memberships: Usuario $user_id ya tiene membresía nivel $current_level, no se asigna nivel $level por referido");
        return false;
    }
    
    // Activar membresía marcada como bono de referido (sin Virtual Coins)
    $membership_id = starter_activate_user_membership($user_id, $level, $days, null, null, true);
    
    if ($membership_id) {
        // Marcar que se otorgó membresía por referido
        update_user_meta($user_id, '_starter_referral_membership_granted', [
            'membership_id' => $membership_id,
            'level' => $level,
            'days' => $days,
            'referrer_id' => $referrer_id,
            'granted_at' => current_time('mysql')
        ]);
        
        // Obtener nombre del nivel
        $level_info = Starter_Memberships::get_membership_level($level);
        $level_name = $level_info['name'] ?? "Nivel $level";
        
        error_log(sprintf(
            'Starter Memberships: Usuario %d recibió membresía %s (%d días) por registro con código de referido (referidor: %d)',
            $user_id, $level_name, $days, $referrer_id
        ));
        
        // Disparar acción para notificaciones u otras integraciones
        do_action('starter_referral_membership_granted', $user_id, $level, $days, $referrer_id, $membership_id);
        
        return true;
    }
    
    error_log("Starter Memberships: Error al activar membresía nivel $level para usuario $user_id");
    return false;
}

/**
 * Obtener información de membresía otorgada por referido
 * 
 * @param int $user_id ID del usuario
 * @return array|false Datos de la membresía por referido o false
 */
function starter_memberships_get_referral_membership_info($user_id) {
    return get_user_meta($user_id, '_starter_referral_membership_granted', true);
}
