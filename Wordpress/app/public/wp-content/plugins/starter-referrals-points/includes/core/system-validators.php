<?php
/**
 * Validadores del sistema de referidos y puntos
 * 
 * Funciones helper para verificar si los sistemas están habilitados
 * y si los usuarios tienen permisos para participar.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verificar si el sistema de puntos está habilitado
 * 
 * @return bool True si está habilitado, false en caso contrario
 */
function starter_rp_is_points_system_enabled() {
    $options = Starter_RP()->get_options();
    return isset($options['enable_points']) && $options['enable_points'];
}

/**
 * Verificar si el sistema de referidos está habilitado
 * 
 * @return bool True si está habilitado, false en caso contrario
 */
function starter_rp_is_referrals_system_enabled() {
    $options = Starter_RP()->get_options();
    return isset($options['enable_referrals']) && $options['enable_referrals'];
}

/**
 * Verificar si un usuario puede participar en el sistema según su rol
 * 
 * @param int $user_id ID del usuario (opcional, por defecto usuario actual)
 * @return bool True si puede participar, false en caso contrario
 */
function starter_rp_can_user_participate($user_id = null) {
    // Si no se especifica usuario, usar el actual
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    // Si no hay usuario logueado, no puede participar
    if (!$user_id) {
        return false;
    }
    
    // Obtener el usuario
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }
    
    // Obtener roles permitidos de la configuración
    $options = Starter_RP()->get_options();
    $allowed_roles = isset($options['allowed_roles']) ? $options['allowed_roles'] : ['customer'];
    
    // Verificar si el rol del usuario está en la lista de roles permitidos
    $user_roles = $user->roles;
    
    // Si el usuario tiene al menos uno de los roles permitidos, puede participar
    foreach ($user_roles as $role) {
        if (in_array($role, $allowed_roles)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Verificar si el usuario puede ganar/usar puntos
 * 
 * @param int $user_id ID del usuario (opcional, por defecto usuario actual)
 * @return bool True si puede usar el sistema de puntos, false en caso contrario
 */
function starter_rp_can_user_use_points($user_id = null) {
    // Si no se proporciona user_id, usar el usuario actual
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    // Verificar si el sistema de puntos está habilitado
    $options = Starter_RP()->get_options();
    $points_enabled = (bool) ($options['enable_points'] ?? 1);
    
    if (!$points_enabled) {
        return false;
    }
    
    // Verificar si el usuario está logueado
    if (!$user_id) {
        return false;
    }
    
    // Verificar si el rol del usuario está permitido
    $user = get_userdata($user_id);
    if (!$user) {
        starter_rp_log('Starter RP: can_user_use_points - No se pudo obtener datos del usuario ' . $user_id);
        return false;
    }
    
    $user_roles = $user->roles;
    $allowed_roles = $options['allowed_roles'] ?? ['customer'];
    
    // Verificar si algún rol del usuario está en la lista de roles permitidos
    foreach ($user_roles as $role) {
        if (in_array($role, $allowed_roles)) {
            return true;
        }
    }
    
    starter_rp_log('Starter RP: can_user_use_points - DENEGADO para usuario ' . $user_id . ' (roles: ' . implode(', ', $user_roles) . ')');
    return false;
}

/**
 * Verificar si el usuario puede participar en referidos
 * 
 * Requiere:
 * - Sistema de referidos habilitado
 * - Rol de usuario permitido
 * - Nivel de membresía >= 1 (Bronce o superior)
 * 
 * @param int $user_id ID del usuario (opcional, por defecto usuario actual)
 * @return bool True si puede usar el sistema de referidos, false en caso contrario
 */
function starter_rp_can_user_use_referrals($user_id = null) {
    // Verificar si el sistema de referidos está habilitado
    if (!starter_rp_is_referrals_system_enabled()) {
        return false;
    }
    
    // Verificar si el usuario puede participar según su rol
    if (!starter_rp_can_user_participate($user_id)) {
        return false;
    }
    
    // Verificar nivel de membresía (requiere mínimo nivel 1 - Bronce)
    if (function_exists('starter_get_user_membership_level')) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $membership_level = starter_get_user_membership_level($user_id);
        
        // Nivel 0 (Zanahoria sin membresía) no tiene acceso a referidos
        if ($membership_level < 1) {
            return false;
        }
    }
    
    return true;
}

/**
 * Verificar si ambos sistemas están habilitados y el usuario puede participar
 * 
 * @param int $user_id ID del usuario (opcional, por defecto usuario actual)
 * @return array Array con el estado de cada sistema
 */
function starter_rp_get_user_system_permissions($user_id = null) {
    return [
        'can_use_points' => starter_rp_can_user_use_points($user_id),
        'can_use_referrals' => starter_rp_can_user_use_referrals($user_id),
        'points_system_enabled' => starter_rp_is_points_system_enabled(),
        'referrals_system_enabled' => starter_rp_is_referrals_system_enabled(),
        'user_can_participate' => starter_rp_can_user_participate($user_id)
    ];
}

/**
 * Log de acceso denegado para depuración
 * 
 * @param string $action Acción que se intentó realizar
 * @param int $user_id ID del usuario
 * @param string $reason Razón por la cual se denegó el acceso
 */
function starter_rp_log_access_denied($action, $user_id = null, $reason = '') {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    $user = get_userdata($user_id);
    $user_info = $user ? $user->user_login : 'Usuario desconocido';
    
    starter_rp_log(sprintf(
        'Starter RP - Acceso denegado: Acción="%s", Usuario="%s" (ID: %d), Razón="%s"',
        $action,
        $user_info,
        $user_id,
        $reason
    ));
} 