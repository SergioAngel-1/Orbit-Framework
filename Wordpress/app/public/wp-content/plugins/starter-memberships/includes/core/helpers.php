<?php
/**
 * Funciones auxiliares para Starter Memberships
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener el nivel de membresía de un usuario
 * 
 * IMPORTANTE: Esta función verifica la expiración en tiempo real.
 * Si la membresía ha expirado (end_date < NOW), retorna nivel 0 y
 * opcionalmente actualiza el estado en la base de datos.
 * 
 * FUENTE DE VERDAD: La tabla starter_user_memberships es la fuente principal.
 * El user_meta se usa solo como caché/fallback y se sincroniza automáticamente
 * si se detecta inconsistencia.
 * 
 * @param int $user_id ID del usuario (0 para usuarios anónimos)
 * @return int Nivel de membresía (0-5). Usuarios anónimos siempre retornan 0.
 */
function starter_get_user_membership_level($user_id) {
    // Usuarios anónimos siempre tienen nivel 0
    if (!$user_id) {
        return 0;
    }
    
    // Primero verificar en la tabla de membresías activas (FUENTE DE VERDAD)
    $membership = starter_memberships_get_user_membership($user_id);
    
    if ($membership) {
        // Si está congelada, retornar nivel 0
        if ($membership->status === 'frozen') {
            // Sincronizar user_meta si está desincronizado
            $meta_level = get_user_meta($user_id, '_membership_level', true);
            if ($meta_level && intval($meta_level) !== 0) {
                update_user_meta($user_id, '_membership_level', 0);
            }
            return 0;
        }
        
        if ($membership->status === 'active') {
            // VERIFICACIÓN EN TIEMPO REAL: Comprobar si la membresía ha expirado
            if ($membership->end_date && strtotime($membership->end_date) < time()) {
                // La membresía ha expirado - actualizar estado y retornar 0
                starter_expire_membership_realtime($membership, $user_id);
                return 0;
            }
            
            $level = intval($membership->membership_level);
            
            // Sincronizar user_meta si está desincronizado
            $meta_level = get_user_meta($user_id, '_membership_level', true);
            if (intval($meta_level) !== $level) {
                update_user_meta($user_id, '_membership_level', $level);
            }
            
            return $level;
        }
        
        // Membresía existe pero no está activa ni congelada (expired, cancelled, etc.)
        // Sincronizar user_meta a 0
        $meta_level = get_user_meta($user_id, '_membership_level', true);
        if ($meta_level && intval($meta_level) !== 0) {
            update_user_meta($user_id, '_membership_level', 0);
            delete_user_meta($user_id, '_membership_end_date');
        }
        return 0;
    }
    
    // No hay registro en la tabla - verificar si hay user_meta huérfano
    $meta_level = get_user_meta($user_id, '_membership_level', true);
    
    if ($meta_level && intval($meta_level) > 0) {
        // INCONSISTENCIA DETECTADA: user_meta tiene nivel pero no hay registro en tabla
        // Esto puede ocurrir por:
        // 1. Migración incompleta
        // 2. Eliminación manual de registro en tabla
        // 3. Error en proceso de expiración
        
        // Limpiar el meta huérfano para mantener consistencia
        error_log(sprintf(
            'Starter Memberships: Inconsistencia detectada - Usuario %d tiene _membership_level=%d pero no hay registro activo en tabla. Limpiando meta.',
            $user_id, intval($meta_level)
        ));
        
        update_user_meta($user_id, '_membership_level', 0);
        delete_user_meta($user_id, '_membership_end_date');
    }
    
    return 0;
}

/**
 * Expirar una membresía en tiempo real
 * 
 * Esta función se llama cuando se detecta que una membresía ha expirado
 * durante una petición (antes de que el cron diario la procese).
 * 
 * @param object $membership Objeto de membresía de la BD
 * @param int $user_id ID del usuario
 */
function starter_expire_membership_realtime($membership, $user_id) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'starter_user_memberships';
    
    // Actualizar estado a expirado
    $wpdb->update(
        $table,
        ['status' => 'expired', 'updated_at' => current_time('mysql')],
        ['id' => $membership->id],
        ['%s', '%s'],
        ['%d']
    );
    
    // Actualizar user meta
    update_user_meta($user_id, '_membership_level', 0);
    delete_user_meta($user_id, '_membership_end_date');
    
    // Registrar en historial
    if (function_exists('starter_memberships_log_action')) {
        starter_memberships_log_action(
            $user_id,
            'expiration',
            $membership->membership_level,
            0,
            ['expired_at' => current_time('mysql'), 'realtime' => true],
            $membership->id
        );
    }
    
    // Disparar acción para que otros plugins puedan reaccionar
    do_action('starter_membership_expired', $user_id, $membership->id);
    
    error_log(sprintf(
        'Starter Memberships: Membresía %d del usuario %d expirada en tiempo real (end_date: %s)',
        $membership->id, $user_id, $membership->end_date
    ));
}

/**
 * Verificar si un usuario tiene acceso a un nivel de membresía específico
 * 
 * @param int $user_id ID del usuario
 * @param int $required_level Nivel requerido
 * @return bool True si tiene acceso
 */
function starter_user_has_membership_access($user_id, $required_level) {
    $user_level = starter_get_user_membership_level($user_id);
    return $user_level >= $required_level;
}

/**
 * Obtener información completa del nivel de membresía de un usuario
 * 
 * @param int $user_id ID del usuario
 * @return array Información del nivel
 */
function starter_get_user_membership_info($user_id) {
    // Obtener membresía de la tabla
    $membership = starter_memberships_get_user_membership($user_id);
    
    // Determinar nivel: de la tabla si existe, sino de user_meta
    if ($membership) {
        // Si está congelada, el nivel efectivo es 0, pero guardamos el original
        $effective_level = ($membership->status === 'frozen') ? 0 : intval($membership->membership_level);
        $original_level = intval($membership->membership_level);
    } else {
        $effective_level = intval(get_user_meta($user_id, '_membership_level', true));
        $original_level = $effective_level;
    }
    
    $level_info = Starter_Memberships::get_membership_level($effective_level);
    
    // Determinar status
    $status = 'none';
    if ($membership) {
        $status = $membership->status;
    } elseif ($effective_level > 0) {
        $status = 'active'; // Tiene nivel en user_meta pero no en tabla
    }
    
    return [
        'level' => $effective_level,
        'original_level' => $original_level,
        'name' => $level_info['name'],
        'slug' => $level_info['slug'],
        'icon' => $level_info['icon'],
        'color' => $level_info['color'],
        'monthly_points' => $level_info['monthly_points'],
        'description' => $level_info['description'],
        'start_date' => $membership ? $membership->start_date : null,
        'end_date' => $membership ? $membership->end_date : null,
        'status' => $status,
        'auto_renew' => $membership ? (bool)$membership->auto_renew : false,
        'granted_by_admin' => $membership && isset($membership->granted_by_admin) ? $membership->granted_by_admin : null
    ];
}

/**
 * Formatear precio en pesos colombianos
 * 
 * @param int $amount Cantidad
 * @return string Precio formateado
 */
function starter_format_cop($amount) {
    return '$' . number_format($amount, 0, ',', '.') . ' COP';
}

/**
 * Formatear Virtual Coins
 * 
 * @param int $amount Cantidad
 * @return string FC formateados
 */
function starter_format_fc($amount) {
    return number_format($amount, 0, ',', '.') . ' FC';
}

/**
 * Verificar si un producto es de membresía
 * 
 * @param int $product_id ID del producto
 * @return bool True si es producto de membresía
 */
function starter_is_membership_product($product_id) {
    return get_post_meta($product_id, '_is_membership_product', true) === 'yes';
}

/**
 * Obtener el nivel de membresía de un producto
 * 
 * @param int $product_id ID del producto
 * @return int|null Nivel de membresía o null
 */
function starter_get_product_membership_level($product_id) {
    if (!starter_is_membership_product($product_id)) {
        return null;
    }
    
    $level = get_post_meta($product_id, '_membership_level', true);
    return $level !== '' ? intval($level) : null;
}

/**
 * Obtener el nivel mínimo de membresía requerido para una categoría
 * 
 * @param int $category_id ID de la categoría
 * @return int Nivel mínimo requerido (0 = público)
 */
function starter_get_category_min_membership($category_id) {
    $min_level = get_term_meta($category_id, '_min_membership_level', true);
    return $min_level !== '' ? intval($min_level) : 0;
}

/**
 * Verificar si un usuario puede ver una categoría
 * 
 * @param int $user_id ID del usuario (0 para visitantes)
 * @param int $category_id ID de la categoría
 * @return bool True si puede ver
 */
function starter_user_can_view_category($user_id, $category_id) {
    $min_level = starter_get_category_min_membership($category_id);
    
    // Si no requiere membresía, todos pueden ver
    if ($min_level === 0) {
        return true;
    }
    
    // Si no hay usuario, no puede ver categorías protegidas
    if (!$user_id) {
        return false;
    }
    
    return starter_user_has_membership_access($user_id, $min_level);
}

/**
 * Obtener productos de membresía disponibles
 * 
 * @return array Lista de productos de membresía
 */
function starter_get_membership_products() {
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_is_membership_product',
                'value' => 'yes',
                'compare' => '='
            ]
        ],
        'orderby' => 'meta_value_num',
        'meta_key' => '_membership_level',
        'order' => 'ASC'
    ];
    
    $products = get_posts($args);
    $result = [];
    
    foreach ($products as $product) {
        $wc_product = wc_get_product($product->ID);
        if (!$wc_product) continue;
        
        $level = intval(get_post_meta($product->ID, '_membership_level', true));
        $level_info = Starter_Memberships::get_membership_level($level);
        
        $result[] = [
            'id' => $product->ID,
            'name' => $wc_product->get_name(),
            'price' => $wc_product->get_price(),
            'regular_price' => $wc_product->get_regular_price(),
            'sale_price' => $wc_product->get_sale_price(),
            'membership_level' => $level,
            'level_name' => $level_info['name'],
            'level_icon' => $level_info['icon'],
            'monthly_points' => intval(get_post_meta($product->ID, '_membership_monthly_points', true)),
            'duration_days' => intval(get_post_meta($product->ID, '_membership_duration_days', true)) ?: 30,
            'benefits' => get_post_meta($product->ID, '_membership_benefits', true),
            'image' => wp_get_attachment_url($wc_product->get_image_id()),
            'permalink' => get_permalink($product->ID)
        ];
    }
    
    return $result;
}

/**
 * Obtener los días desde el registro de un usuario
 * 
 * @param int $user_id ID del usuario
 * @return int Días desde el registro (0 si no existe el usuario)
 */
function starter_get_user_registration_days($user_id) {
    if (!$user_id) {
        return 0;
    }
    
    $user = get_userdata($user_id);
    if (!$user || !$user->user_registered) {
        return 0;
    }
    
    $registration_date = new DateTime($user->user_registered);
    $now = new DateTime();
    $diff = $now->diff($registration_date);
    
    return $diff->days;
}

/**
 * Verificar si un usuario cumple con la antigüedad mínima para un nivel
 * 
 * @param int $user_id ID del usuario
 * @param int $level_id ID del nivel de membresía
 * @return array ['eligible' => bool, 'days_registered' => int, 'days_required' => int, 'days_remaining' => int]
 */
function starter_check_user_seniority_eligibility($user_id, $level_id) {
    $level_info = Starter_Memberships::get_membership_level($level_id);
    $min_days = $level_info['min_registration_days'] ?? 0;
    $user_days = starter_get_user_registration_days($user_id);
    
    return [
        'eligible' => $user_days >= $min_days,
        'days_registered' => $user_days,
        'days_required' => $min_days,
        'days_remaining' => max(0, $min_days - $user_days)
    ];
}
