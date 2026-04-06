<?php
/**
 * Popups - Funciones Helper
 * 
 * Funciones auxiliares para el sistema de popups
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fecha de corte para el sistema de membresías por antigüedad
 * Usuarios registrados ANTES de esta fecha son elegibles para la membresía gratuita
 * Esta es la fecha de lanzamiento del sistema de membresías
 */
define('STARTER_LEGACY_MEMBERSHIP_CUTOFF_DATE', '2026-01-30');

/**
 * Verificar si un usuario es elegible para el popup de membresía por antigüedad
 * 
 * Condiciones:
 * 1. Usuario creado ANTES de la fecha de corte (lanzamiento del sistema de membresías)
 * 2. Usuario NO ha ACEPTADO la membresía de antigüedad
 * 
 * Este popup es OBLIGATORIO y no se puede cerrar sin aceptar.
 * El popup seguirá apareciendo hasta que el usuario ACEPTE.
 * 
 * @param int $user_id ID del usuario
 * @return bool True si es elegible (debe ver el popup)
 */
function starter_user_eligible_for_legacy_membership($user_id) {
    if (!$user_id) {
        return false;
    }
    
    // 1. Verificar si ya ACEPTÓ - solo deja de aparecer cuando acepta
    $legacy_status = get_user_meta($user_id, '_starter_legacy_membership_status', true);
    if ($legacy_status === 'accepted') {
        return false; // Ya aceptó, no mostrar más
    }
    
    // 2. Verificar que el usuario fue creado ANTES de la fecha de corte
    $user = get_userdata($user_id);
    if (!$user) {
        return false;
    }
    
    $cutoff_date = strtotime(STARTER_LEGACY_MEMBERSHIP_CUTOFF_DATE);
    $registration_date = strtotime($user->user_registered);
    
    // Elegible si se registró antes de la fecha de corte
    return $registration_date < $cutoff_date;
}

/**
 * Verificar si un usuario debe ver el popup de expiración de membresía
 * 
 * @param int $user_id ID del usuario
 * @return array|false Array con info de expiración o false si no aplica
 */
function starter_user_membership_expiring_soon($user_id) {
    if (!$user_id) {
        return false;
    }
    
    // Verificar si tiene membresía activa
    if (!function_exists('starter_memberships_get_user_membership')) {
        return false;
    }
    
    $membership = starter_memberships_get_user_membership($user_id);
    
    if (!$membership || $membership->status !== 'active') {
        return false;
    }
    
    // Calcular días restantes
    $end_date = strtotime($membership->end_date);
    $days_remaining = ceil(($end_date - time()) / DAY_IN_SECONDS);
    
    // Mostrar popup si quedan 2 días o menos
    if ($days_remaining <= 2 && $days_remaining > 0) {
        // Verificar si ya se mostró hoy
        $last_shown = get_user_meta($user_id, '_starter_expiration_popup_shown', true);
        $today = date('Y-m-d');
        
        if ($last_shown === $today) {
            return false; // Ya se mostró hoy
        }
        
        // Obtener info del nivel
        $level_name = 'Membresía';
        $level_icon = '🥕';
        if (class_exists('Starter_Memberships')) {
            $level_info = Starter_Memberships::get_membership_level($membership->membership_level);
            $level_name = $level_info['name'] ?? "Nivel {$membership->membership_level}";
            $level_icon = $level_info['icon'] ?? '🥕';
        }
        
        // Obtener periodicidad del producto si existe
        $renewal_period = 'monthly';
        $renewal_period_label = 'Mensual';
        if ($membership->product_id) {
            $product_renewal = get_post_meta($membership->product_id, '_membership_renewal_period', true);
            if ($product_renewal) {
                $renewal_period = $product_renewal;
                $period_labels = [
                    'none' => 'Sin renovación',
                    'monthly' => 'Mensual',
                    'bimonthly' => 'Bimestral',
                    'quarterly' => 'Trimestral',
                    'biannual' => 'Semestral',
                    'annual' => 'Anual'
                ];
                $renewal_period_label = $period_labels[$renewal_period] ?? $renewal_period;
            }
        }
        
        return array(
            'days_remaining' => $days_remaining,
            'end_date' => $membership->end_date,
            'level' => $membership->membership_level,
            'level_name' => $level_name,
            'level_icon' => $level_icon,
            'renewal_period' => $renewal_period,
            'renewal_period_label' => $renewal_period_label,
        );
    }
    
    return false;
}

/**
 * Verificar si un usuario tiene membresía expirada recientemente
 * 
 * @param int $user_id ID del usuario
 * @return array|false Array con info de la membresía expirada o false si no aplica
 */
function starter_user_membership_expired($user_id) {
    if (!$user_id) {
        return false;
    }
    
    // Verificar si tiene membresía
    if (!function_exists('starter_memberships_get_user_membership')) {
        return false;
    }
    
    $membership = starter_memberships_get_user_membership($user_id);
    
    // Si no tiene membresía o está activa, no mostrar
    if (!$membership) {
        return false;
    }
    
    // Solo mostrar si la membresía expiró (status expired o end_date pasada)
    $is_expired = $membership->status === 'expired';
    $end_date = strtotime($membership->end_date);
    $days_since_expiry = floor((time() - $end_date) / DAY_IN_SECONDS);
    
    if (!$is_expired && $days_since_expiry <= 0) {
        return false; // No ha expirado
    }
    
    // Solo mostrar si expiró hace menos de 30 días
    if ($days_since_expiry > 30) {
        return false;
    }
    
    // Verificar si ya se mostró esta semana
    $last_shown = get_user_meta($user_id, '_starter_expired_popup_shown', true);
    if ($last_shown) {
        $last_shown_time = strtotime($last_shown);
        $days_since_shown = floor((time() - $last_shown_time) / DAY_IN_SECONDS);
        
        if ($days_since_shown < 7) {
            return false; // Ya se mostró esta semana
        }
    }
    
    // Obtener info del nivel que tenía
    $level_info = null;
    if (class_exists('Starter_Memberships')) {
        $level_info = Starter_Memberships::get_membership_level($membership->membership_level);
    }
    
    return array(
        'expired_level' => $membership->membership_level,
        'expired_level_name' => $level_info ? $level_info['name'] : 'Membresía',
        'expired_level_icon' => $level_info ? $level_info['icon'] : '🥕',
        'end_date' => $membership->end_date,
        'days_since_expiry' => $days_since_expiry,
    );
}

/**
 * Verificar si debe mostrarse el popup de login para usuarios anónimos
 * 
 * @return array|false Array con datos o false si no aplica
 */
function starter_should_show_login_prompt() {
    // Solo para usuarios no autenticados
    if (is_user_logged_in()) {
        return false;
    }
    
    // El control de frecuencia se maneja en el frontend con localStorage
    return array(
        'show' => true,
    );
}

/**
 * Verificar si un usuario tiene bonificación de referido pendiente de notificar
 * 
 * Este popup se muestra UNA SOLA VEZ en el primer inicio de sesión cuando:
 * 1. El usuario fue registrado con código de referido
 * 2. El usuario recibió membresía por referido
 * 3. El usuario aún no ha sido notificado del bonus
 * 
 * @param int $user_id ID del usuario
 * @return array|false Array con info del bonus o false si no aplica
 */
function starter_user_has_referral_bonus($user_id) {
    global $wpdb;
    
    if (!$user_id) {
        return false;
    }
    
    // Verificar si ya se notificó
    $notified = get_user_meta($user_id, '_starter_referral_bonus_notified', true);
    if ($notified === 'yes') {
        return false;
    }
    
    // Verificar si se otorgó membresía por referido
    $membership_granted = get_user_meta($user_id, '_starter_referral_membership_granted', true);
    
    if ($membership_granted && is_array($membership_granted)) {
        // Tiene membresía otorgada por referido
        $referrer_id = $membership_granted['referrer_id'] ?? 0;
        $level = $membership_granted['level'] ?? 2;
        $days = $membership_granted['days'] ?? 30;
        
        // Obtener info del referidor
        $referrer = get_userdata($referrer_id);
        $referrer_name = $referrer ? $referrer->display_name : 'Tu referidor';
        
        // Obtener nombre del nivel
        $level_name = 'Plata';
        $level_icon = '🥈';
        if (class_exists('Starter_Memberships')) {
            $level_info = Starter_Memberships::get_membership_level($level);
            $level_name = $level_info['name'] ?? "Nivel $level";
            $level_icon = $level_info['icon'] ?? '🥕';
        }
        
        return array(
            'referrer_id' => intval($referrer_id),
            'referrer_name' => $referrer_name,
            'bonus_type' => 'membership',
            'membership_level' => $level,
            'membership_level_name' => $level_name,
            'membership_level_icon' => $level_icon,
            'bonus_duration' => $days,
            'granted_at' => $membership_granted['granted_at'] ?? null,
        );
    }
    
    // Fallback: buscar si el usuario tiene un referidor en la tabla de referidos
    // (para casos donde se registró con código pero aún no se procesó la membresía)
    $table = $wpdb->prefix . 'starter_referrals';
    $referrer_id = $wpdb->get_var($wpdb->prepare("
        SELECT referrer_id FROM $table WHERE user_id = %d AND referrer_id IS NOT NULL
    ", $user_id));
    
    // También verificar el meta de referidor pendiente (por si aún no se procesó)
    if (!$referrer_id) {
        $referrer_id = get_user_meta($user_id, '_starter_pending_referral_points', true);
    }
    
    if (!$referrer_id) {
        return false;
    }
    
    // Verificar si hay membresía pendiente de asignar
    $pending_membership = get_user_meta($user_id, '_starter_pending_referral_membership', true);
    
    if ($pending_membership && is_array($pending_membership)) {
        // Hay membresía pendiente (usuario aún no aprobado)
        $level = $pending_membership['level'] ?? 2;
        $days = $pending_membership['days'] ?? 30;
        
        // Obtener info del referidor
        $referrer = get_userdata($referrer_id);
        $referrer_name = $referrer ? $referrer->display_name : 'Tu referidor';
        
        // Obtener nombre del nivel
        $level_name = 'Plata';
        $level_icon = '🥈';
        if (class_exists('Starter_Memberships')) {
            $level_info = Starter_Memberships::get_membership_level($level);
            $level_name = $level_info['name'] ?? "Nivel $level";
            $level_icon = $level_info['icon'] ?? '🥕';
        }
        
        return array(
            'referrer_id' => intval($referrer_id),
            'referrer_name' => $referrer_name,
            'bonus_type' => 'membership_pending', // Membresía pendiente de aprobar
            'membership_level' => $level,
            'membership_level_name' => $level_name,
            'membership_level_icon' => $level_icon,
            'bonus_duration' => $days,
            'pending_approval' => true,
        );
    }
    
    // Si llegamos aquí, el usuario tiene referidor pero no se le otorgó membresía
    // (posiblemente el referidor no tenía el beneficio habilitado)
    return false;
}

/**
 * Procesar y formatear un popup para la API
 * 
 * @param WP_Post $popup El post del popup
 * @param int $user_id ID del usuario actual
 * @return array|null Array con datos del popup o null si no debe mostrarse
 */
function starter_process_popup_for_api($popup, $user_id = 0) {
    $popup_type = get_post_meta($popup->ID, '_popup_type', true);
    $is_active = get_post_meta($popup->ID, '_popup_active', true);
    
    // Verificar si está activo
    if ($is_active !== '1' && $is_active !== 'yes') {
        return null;
    }
    
    // Verificar elegibilidad según el tipo de popup
    $eligibility_data = null;
    
    switch ($popup_type) {
        case 'membership_legacy':
            if (!starter_user_eligible_for_legacy_membership($user_id)) {
                return null;
            }
            $eligibility_data = array('eligible' => true);
            break;
            
        case 'membership_expiration':
            $expiration_info = starter_user_membership_expiring_soon($user_id);
            if (!$expiration_info) {
                return null;
            }
            $eligibility_data = $expiration_info;
            break;
            
        case 'referral_bonus':
            $bonus_info = starter_user_has_referral_bonus($user_id);
            if (!$bonus_info) {
                return null;
            }
            $eligibility_data = $bonus_info;
            break;
            
        case 'membership_expired':
            $expired_info = starter_user_membership_expired($user_id);
            if (!$expired_info) {
                return null;
            }
            $eligibility_data = $expired_info;
            break;
            
        case 'login_prompt':
            $login_info = starter_should_show_login_prompt();
            if (!$login_info) {
                return null;
            }
            $eligibility_data = $login_info;
            break;
            
        case 'general':
            // Los popups generales pueden tener restricciones de membresía
            $min_membership = get_post_meta($popup->ID, '_popup_min_membership', true);
            $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
            
            if ($min_membership > 0) {
                // CRÍTICO: Usar verificación JWT, no cookies de sesión
                $user_level = function_exists('starter_get_jwt_user_membership_level') 
                    ? starter_get_jwt_user_membership_level() 
                    : 0;
                    
                if ($user_level < $min_membership) {
                    return null;
                }
            }
            
            // Verificar si ya se mostró (usando cookie/session tracking en frontend)
            $eligibility_data = array('show' => true);
            break;
            
        default:
            return null;
    }
    
    // Obtener imágenes
    $image = get_post_meta($popup->ID, '_popup_image', true);
    $image_mobile = get_post_meta($popup->ID, '_popup_image_mobile', true);
    
    // Usar imagen destacada como respaldo
    if (empty($image) && has_post_thumbnail($popup->ID)) {
        $image = get_the_post_thumbnail_url($popup->ID, 'full');
    }
    
    if (empty($image_mobile)) {
        $image_mobile = $image;
    }
    
    // Obtener configuración de visualización
    $display_frequency = get_post_meta($popup->ID, '_popup_display_frequency', true) ?: 'once_per_session';
    $dismissible = get_post_meta($popup->ID, '_popup_dismissible', true) !== '0';
    $show_overlay = get_post_meta($popup->ID, '_popup_show_overlay', true) !== '0';
    $display_delay = intval(get_post_meta($popup->ID, '_popup_display_delay', true) ?: 0);
    
    // Obtener URL de la imagen (para hacer la imagen clickeable)
    $image_url = get_post_meta($popup->ID, '_popup_image_url', true);
    
    return array(
        'id' => $popup->ID,
        'title' => $popup->post_title,
        'content' => get_post_meta($popup->ID, '_popup_content', true),
        'type' => $popup_type,
        'image' => $image,
        'imageMobile' => $image_mobile,
        'imageUrl' => $image_url,
        'dismissible' => $dismissible,
        'showOverlay' => $show_overlay,
        'displayDelay' => $display_delay,
        'eligibilityData' => $eligibility_data,
        'priority' => intval(get_post_meta($popup->ID, '_popup_priority', true) ?: 10),
    );
}

/**
 * Marcar que se mostró el popup de expiración
 * 
 * @param int $user_id ID del usuario
 */
function starter_mark_expiration_popup_shown($user_id) {
    if ($user_id) {
        update_user_meta($user_id, '_starter_expiration_popup_shown', date('Y-m-d'));
    }
}

/**
 * Marcar que se mostró el popup de membresía expirada
 * 
 * @param int $user_id ID del usuario
 */
function starter_mark_expired_popup_shown($user_id) {
    if ($user_id) {
        update_user_meta($user_id, '_starter_expired_popup_shown', date('Y-m-d'));
    }
}

/**
 * Marcar que se notificó el bonus de referido
 * 
 * @param int $user_id ID del usuario
 */
function starter_mark_referral_bonus_notified($user_id) {
    if ($user_id) {
        update_user_meta($user_id, '_starter_referral_bonus_notified', 'yes');
        update_user_meta($user_id, '_starter_referral_bonus_notified_date', current_time('mysql'));
    }
}

/**
 * Registrar respuesta del usuario al popup de membresía por antigüedad
 * 
 * Al aceptar:
 * 1. Guarda las aceptaciones (veracidad de datos, términos, membresías)
 * 2. Asigna la membresía de Antigüedad (nivel 5)
 * 3. Otorga Virtual Coins de bienvenida al sistema
 * 
 * @param int $user_id ID del usuario
 * @param string $response 'accepted' o 'rejected'
 * @return bool True si se registró correctamente
 */
function starter_register_legacy_membership_response($user_id, $response) {
    if (!$user_id || !in_array($response, array('accepted', 'rejected'))) {
        return false;
    }
    
    $current_time = current_time('mysql');
    
    // Registrar la respuesta
    update_user_meta($user_id, '_starter_legacy_membership_status', $response);
    update_user_meta($user_id, '_starter_legacy_membership_response_date', $current_time);
    
    // Si aceptó, procesar todas las acciones
    if ($response === 'accepted') {
        // 1. Guardar aceptaciones legales (equivalente a los checks del registro)
        update_user_meta($user_id, '_starter_accepted_data_veracity', '1');
        update_user_meta($user_id, '_starter_accepted_data_veracity_date', $current_time);
        update_user_meta($user_id, '_starter_accepted_terms', '1');
        update_user_meta($user_id, '_starter_accepted_terms_date', $current_time);
        update_user_meta($user_id, '_starter_accepted_membership_system', '1');
        update_user_meta($user_id, '_starter_accepted_membership_system_date', $current_time);
        
        // 2. Activar la membresía por antigüedad (cedida por admin, sin Virtual Coins)
        if (function_exists('starter_activate_user_membership')) {
            global $wpdb;
            
            // Nivel 5 = Membresía por Antigüedad, periodo indefinido (36500 días = ~100 años)
            $membership_id = starter_activate_user_membership($user_id, 5, 36500);
            
            if ($membership_id) {
                // Marcar como cedida por admin (el sistema actúa como admin)
                // Esto evita que se otorguen Virtual Coins
                $table = $wpdb->prefix . 'starter_user_memberships';
                $wpdb->update(
                    $table,
                    array('granted_by_admin' => 1), // 1 = sistema/admin
                    array('id' => $membership_id),
                    array('%d'),
                    array('%d')
                );
                
                // Registrar en el historial
                if (function_exists('starter_memberships_log_action')) {
                    starter_memberships_log_action(
                        $user_id,
                        'activation',
                        null,
                        5,
                        array(
                            'source' => 'legacy_popup', 
                            'auto_assigned' => false,
                            'granted_by_admin' => true
                        ),
                        $membership_id
                    );
                }
                
                // Guardar referencia de la membresía
                update_user_meta($user_id, '_starter_legacy_membership_id', $membership_id);
            }
        }
        
        // NOTA: Las membresías cedidas por admin NO otorgan Virtual Coins
        
        return true;
    }
    
    // Si rechazó, solo registrar que rechazó (ya se guardó arriba)
    return true;
}
