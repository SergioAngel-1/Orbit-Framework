<?php
/**
 * Endpoints de API para membresías de usuario
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener membresía del usuario actual
 */
function starter_api_get_user_membership($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Debes iniciar sesión', ['status' => 401]);
    }
    
    $membership_info = starter_get_user_membership_info($user_id);
    $membership = starter_memberships_get_user_membership($user_id);
    
    // Obtener periodicidad y puntos del producto de membresía si existe
    $renewal_period = 'monthly'; // Default
    $duration_days = 30; // Default
    $product_monthly_points = null; // Puntos del producto (si existe)
    
    if ($membership && $membership->product_id) {
        $product_renewal = get_post_meta($membership->product_id, '_membership_renewal_period', true);
        $product_duration = get_post_meta($membership->product_id, '_membership_duration_days', true);
        $product_points = get_post_meta($membership->product_id, '_membership_monthly_points', true);
        
        if ($product_renewal) {
            $renewal_period = $product_renewal;
        }
        if ($product_duration) {
            $duration_days = intval($product_duration);
        }
        if ($product_points) {
            $product_monthly_points = intval($product_points);
        }
    }
    
    // Mapear periodo a texto legible
    $period_labels = [
        'none' => 'Sin renovación',
        'monthly' => 'Mensual',
        'bimonthly' => 'Bimestral',
        'quarterly' => 'Trimestral',
        'biannual' => 'Semestral',
        'annual' => 'Anual'
    ];
    
    // Obtener datos de entregas gratis
    $free_deliveries = null;
    if (function_exists('starter_benefit_registry')) {
        $handler = starter_benefit_registry()->get('free_deliveries');
        if ($handler) {
            $free_deliveries = $handler->apply($user_id, []);
        }
    }
    
    // Obtener datos de muestras gratis
    $free_samples = null;
    if (function_exists('starter_benefit_registry')) {
        $handler = starter_benefit_registry()->get('free_samples');
        if ($handler) {
            $free_samples = $handler->apply($user_id, []);
        }
    }
    
    // Obtener todos los beneficios activos del usuario (optimización: evita llamada adicional a /benefits/active)
    $active_benefits = [];
    if (function_exists('starter_benefit_registry')) {
        $registry = starter_benefit_registry();
        $all_active = $registry->get_active_benefits_for_user($user_id);
        
        foreach ($all_active as $key => $benefit) {
            $item = [
                'key' => $key,
                'name' => $benefit['name'],
                'description' => $benefit['description'],
                'display_value' => $benefit['display_value'],
                'icon' => starter_get_benefit_icon($key)
            ];
            
            // Para category_discount, agregar nombres de categorías
            if ($key === 'category_discount') {
                $item['categories'] = starter_get_category_discount_categories($user_id);
            }
            
            $active_benefits[] = $item;
        }
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'level' => $membership_info['level'],
            'name' => $membership_info['name'],
            'slug' => $membership_info['slug'],
            'icon' => $membership_info['icon'],
            'color' => $membership_info['color'],
            'monthly_points' => $product_monthly_points !== null ? $product_monthly_points : $membership_info['monthly_points'],
            'description' => $membership_info['description'],
            'start_date' => $membership_info['start_date'],
            'end_date' => $membership_info['end_date'],
            'status' => $membership_info['status'],
            'auto_renew' => $membership_info['auto_renew'],
            'days_remaining' => $membership ? max(0, ceil((strtotime($membership->end_date) - time()) / DAY_IN_SECONDS)) : null,
            'is_active' => $membership && $membership->status === 'active',
            'can_upgrade' => $membership_info['level'] < 4 && $membership_info['level'] !== 5,
            'renewal_period' => $renewal_period,
            'renewal_period_label' => $period_labels[$renewal_period] ?? $renewal_period,
            'duration_days' => $duration_days,
            // Beneficios especiales (entregas y muestras gratis)
            'benefits' => [
                'free_deliveries' => $free_deliveries,
                'free_samples' => $free_samples
            ],
            // Todos los beneficios activos (optimización: evita llamada adicional)
            'active_benefits' => $active_benefits
        ]
    ], 200);
}

/**
 * Obtener historial de membresías del usuario
 */
function starter_api_get_membership_history($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Debes iniciar sesión', ['status' => 401]);
    }
    
    $limit = $request->get_param('limit') ?: 10;
    $history = starter_memberships_get_user_history($user_id, $limit);
    
    $formatted_history = [];
    foreach ($history as $entry) {
        $old_level_info = $entry->old_level !== null ? Starter_Memberships::get_membership_level($entry->old_level) : null;
        $new_level_info = $entry->new_level !== null ? Starter_Memberships::get_membership_level($entry->new_level) : null;
        
        $formatted_history[] = [
            'id' => $entry->id,
            'action' => $entry->action,
            'action_label' => starter_get_action_label($entry->action),
            'old_level' => $entry->old_level,
            'old_level_name' => $old_level_info ? $old_level_info['name'] : null,
            'new_level' => $entry->new_level,
            'new_level_name' => $new_level_info ? $new_level_info['name'] : null,
            'details' => $entry->details ? json_decode($entry->details, true) : null,
            'created_at' => $entry->created_at
        ];
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $formatted_history
    ], 200);
}

/**
 * Obtener etiqueta legible para una acción
 */
function starter_get_action_label($action) {
    $labels = [
        'activation' => 'Activación de membresía',
        'renewal' => 'Renovación',
        'upgrade' => 'Mejora de nivel',
        'downgrade' => 'Cambio de nivel',
        'expiration' => 'Expiración',
        'cancellation' => 'Cancelación',
        'admin_activation' => 'Activación por administrador',
        'admin_deactivation' => 'Desactivación por administrador'
    ];
    
    return $labels[$action] ?? $action;
}

/**
 * Obtener estadísticas de membresía del usuario
 */
function starter_api_get_membership_stats($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Debes iniciar sesión', ['status' => 401]);
    }
    
    $stats = starter_get_user_membership_stats($user_id);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $stats
    ], 200);
}

/**
 * Verificar acceso a una categoría
 */
function starter_api_check_category_access($request) {
    $category_id = $request->get_param('id');
    $user_id = function_exists('starter_get_jwt_user_id') ? starter_get_jwt_user_id() : get_current_user_id();
    
    $min_level = starter_get_category_min_membership($category_id);
    $user_level = $user_id ? starter_get_user_membership_level($user_id) : 0;
    $has_access = starter_user_can_view_category($user_id, $category_id);
    
    $min_level_info = Starter_Memberships::get_membership_level($min_level);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'category_id' => intval($category_id),
            'has_access' => $has_access,
            'user_level' => $user_level,
            'required_level' => $min_level,
            'required_level_name' => $min_level_info['name'],
            'required_level_icon' => $min_level_info['icon'],
            'is_public' => $min_level === 0
        ]
    ], 200);
}

/**
 * Obtener categorías accesibles para el usuario
 */
function starter_api_get_accessible_categories($request) {
    $user_id = function_exists('starter_get_jwt_user_id') ? starter_get_jwt_user_id() : get_current_user_id();
    $user_level = $user_id ? starter_get_user_membership_level($user_id) : 0;
    
    // Obtener todas las categorías de productos
    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false
    ]);
    
    $accessible = [];
    
    foreach ($categories as $category) {
        $min_level = starter_get_category_min_membership($category->term_id);
        
        if ($user_level >= $min_level) {
            $min_level_info = Starter_Memberships::get_membership_level($min_level);
            $accessible[] = [
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'count' => $category->count,
                'min_level' => $min_level,
                'min_level_name' => $min_level_info['name'],
                'min_level_icon' => $min_level_info['icon']
            ];
        }
    }
    
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'user_level' => $user_level,
            'accessible' => $accessible
        ]
    ], 200);
}

/**
 * Obtener beneficios del usuario actual
 */
function starter_api_get_user_benefits($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Debes iniciar sesión', ['status' => 401]);
    }
    
    $level = starter_get_user_membership_level($user_id);
    $benefits = starter_format_benefits_for_display($level);
    $active_benefits = starter_get_user_active_benefits($user_id);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'level' => $level,
            'benefits' => $benefits,
            'raw_benefits' => $active_benefits
        ]
    ], 200);
}

/**
 * Obtener beneficios de un nivel específico (público)
 */
function starter_api_get_level_benefits($request) {
    $level = $request->get_param('level');
    
    if ($level === null || $level < 0 || $level > 5) {
        return new WP_Error('invalid_level', 'Nivel no válido (0-5)', ['status' => 400]);
    }
    
    $benefits = starter_format_benefits_for_display($level);
    $level_info = Starter_Memberships::get_membership_level($level);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'level' => $level,
            'level_info' => $level_info,
            'benefits' => $benefits
        ]
    ], 200);
}

/**
 * Obtener estadísticas de administración
 */
function starter_api_get_admin_membership_stats($request) {
    $stats = starter_memberships_get_stats();
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $stats
    ], 200);
}

/**
 * Asignar membresía a usuario (admin)
 */
function starter_api_admin_assign_membership($request) {
    $user_id = $request->get_param('user_id');
    $level = $request->get_param('level');
    $duration_days = $request->get_param('duration_days') ?: 30;
    
    if (!$user_id || !get_user_by('ID', $user_id)) {
        return new WP_Error('invalid_user', 'Usuario no válido', ['status' => 400]);
    }
    
    if ($level === null || $level < 0 || $level > 5) {
        return new WP_Error('invalid_level', 'Nivel de membresía no válido (0-5)', ['status' => 400]);
    }
    
    if ($level === 0) {
        // Desactivar membresía
        starter_cancel_user_membership($user_id, 'Desactivado por administrador');
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Membresía desactivada'
        ], 200);
    }
    
    // Activar membresía
    $membership_id = starter_activate_user_membership($user_id, $level, $duration_days);
    
    if (!$membership_id) {
        return new WP_Error('activation_failed', 'Error al activar membresía', ['status' => 500]);
    }
    
    // Registrar acción de admin
    starter_memberships_log_action(
        $user_id,
        'admin_activation',
        null,
        $level,
        ['admin_id' => get_current_user_id(), 'duration_days' => $duration_days],
        $membership_id
    );
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Membresía activada correctamente',
        'data' => [
            'membership_id' => $membership_id,
            'user_id' => $user_id,
            'level' => $level,
            'duration_days' => $duration_days
        ]
    ], 200);
}

/**
 * Obtener icono de un beneficio
 * Helper function para uso en api-memberships.php
 * 
 * @param string $key
 * @return string
 */
function starter_get_benefit_icon(string $key): string {
    $icons = [
        'category_discount' => '🏷️',
        'referral_bonus' => '👥',
        'referral_membership_bonus' => '🎁',
        'partner_discount_licorera' => '🍺',
        'delivery_options' => '🚚',
        'free_deliveries' => '📦',
        'partner_club_casa_kush' => '🏠',
        'free_samples' => '🌿',
        'security_benefits' => '🛡️',
        'events_discount' => '🎉',
        'exclusive_products' => '⭐',
        'exclusive_content' => '📚',
        'early_access' => '🚀',
        'priority_support' => '💬',
        'monthly_points' => '🌸',
        'extended_period' => '⏱️',
        'points_multiplier' => '✨',
        'birthday_bonus' => '🎂',
        'free_shipping' => '🚚'
    ];
    
    return $icons[$key] ?? '✨';
}

/**
 * Obtener nombres de categorías para el beneficio category_discount
 * Helper function para uso en api-memberships.php
 * 
 * @param int $user_id
 * @return array
 */
function starter_get_category_discount_categories(int $user_id): array {
    if (!function_exists('starter_benefit_registry')) {
        return ['Todas las categorías'];
    }
    
    $registry = starter_benefit_registry();
    $handler = $registry->get('category_discount');
    
    if (!$handler) {
        return ['Todas las categorías'];
    }
    
    $config = $handler->get_config_for_user($user_id);
    
    if (!$config || empty($config['categories'])) {
        return ['Todas las categorías'];
    }
    
    $category_names = [];
    foreach ($config['categories'] as $cat_id) {
        $term = get_term($cat_id, 'product_cat');
        if ($term && !is_wp_error($term)) {
            $category_names[] = $term->name;
        }
    }
    
    return !empty($category_names) ? $category_names : ['Todas las categorías'];
}

/**
 * DEBUG: Endpoint para verificar datos de muestras gratis
 * Acceso: GET /starter/v1/membership/debug/free-samples
 */
function starter_api_debug_free_samples($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Debes iniciar sesión', ['status' => 401]);
    }
    
    $debug_data = [
        'user_id' => $user_id,
        'timestamp' => current_time('mysql'),
    ];
    
    // Obtener datos directos de user_meta
    $debug_data['raw_meta'] = [
        'orders_count' => get_user_meta($user_id, '_starter_free_samples_orders_count', true),
        'grams_claimed' => get_user_meta($user_id, '_starter_free_samples_claimed', true),
        'period_start' => get_user_meta($user_id, '_starter_free_samples_period_start', true),
    ];
    
    // Obtener nivel de membresía
    $debug_data['membership_level'] = starter_get_user_membership_level($user_id);
    
    // Obtener configuración del beneficio
    if (function_exists('starter_benefit_registry')) {
        $handler = starter_benefit_registry()->get('free_samples');
        if ($handler) {
            $debug_data['handler_exists'] = true;
            $debug_data['is_enabled'] = $handler->is_enabled_for_user($user_id);
            $debug_data['config'] = $handler->get_config_for_user($user_id);
            $debug_data['apply_result'] = $handler->apply($user_id, []);
        } else {
            $debug_data['handler_exists'] = false;
        }
    } else {
        $debug_data['registry_exists'] = false;
    }
    
    // Obtener beneficios del nivel
    $debug_data['level_benefits'] = starter_get_level_benefits($debug_data['membership_level']);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $debug_data
    ], 200);
}

/**
 * Enmascara un número de cédula para exposición pública.
 * Ejemplo: '1023456789' → '102.***.789'
 * Muestra los primeros 3 y últimos 3 dígitos, oculta el resto.
 *
 * @param string $doc Número de documento original
 * @return string|null Documento enmascarado o null si vacío
 */
function starter_mask_document_id($doc) {
    if (empty($doc)) {
        return null;
    }
    $doc = trim($doc);
    $len = mb_strlen($doc);
    if ($len <= 6) {
        // Documento muy corto: mostrar primer y último carácter
        return mb_substr($doc, 0, 1) . str_repeat('*', max($len - 2, 0)) . mb_substr($doc, -1);
    }
    return mb_substr($doc, 0, 3) . '.***.'. mb_substr($doc, -3);
}

/**
 * Tiempo de vida del token de verificación (24 horas).
 */
define('STARTER_VERIFY_TOKEN_TTL', 24 * HOUR_IN_SECONDS);

/**
 * Genera o recupera el token de verificación de un usuario.
 * El token expira después de STARTER_VERIFY_TOKEN_TTL segundos y se regenera automáticamente.
 * Usa random_bytes para entropía real (no determinista) + almacena en user_meta con timestamp.
 *
 * @param int  $user_id       ID del usuario
 * @param bool $force_refresh  Forzar regeneración del token
 * @return string Token de 32 caracteres hexadecimales
 */
function starter_get_verification_token($user_id, $force_refresh = false) {
    global $wpdb;
    
    if (!$force_refresh) {
        // Leer directamente de DB para evitar object cache stale
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
            $user_id,
            '_membership_verify_token'
        ));
        $expires_row = $wpdb->get_row($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
            $user_id,
            '_membership_verify_token_expires'
        ));
        
        $stored = $existing ? $existing->meta_value : '';
        $expires = $expires_row ? $expires_row->meta_value : '';
        
        // Retornar existente si no ha expirado y es resoluble por lookup inverso
        if ($stored && $expires && intval($expires) > time()) {
            // Verificar integridad: que el token se puede encontrar por búsqueda inversa
            $lookup = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                '_membership_verify_token',
                $stored
            ));
            if ($lookup && intval($lookup) === $user_id) {
                return $stored;
            }
            // Token huérfano o duplicado — forzar regeneración
        }
    }
    
    // Limpiar TODAS las entradas previas (evitar duplicados en usermeta)
    delete_user_meta($user_id, '_membership_verify_token');
    delete_user_meta($user_id, '_membership_verify_token_expires');
    
    // Generar token aleatorio (no determinista) con entropía real
    $token = bin2hex(random_bytes(16)); // 32 hex chars = 128 bits de entropía
    $expires_at = time() + STARTER_VERIFY_TOKEN_TTL;
    
    // Insertar como entrada única (limpiamos arriba, así que no hay duplicados)
    add_user_meta($user_id, '_membership_verify_token', $token, true);
    add_user_meta($user_id, '_membership_verify_token_expires', $expires_at, true);
    
    // Invalidar object cache de user meta para este usuario
    wp_cache_delete($user_id, 'user_meta');
    
    return $token;
}

/**
 * Invalida el token de verificación de un usuario.
 * Se usa al desactivar/expirar membresía para revocar QRs compartidos.
 *
 * @param int $user_id ID del usuario
 */
function starter_invalidate_verification_token($user_id) {
    delete_user_meta($user_id, '_membership_verify_token');
    delete_user_meta($user_id, '_membership_verify_token_expires');
    wp_cache_delete($user_id, 'user_meta');
}

/**
 * Busca un usuario por su token de verificación.
 * Valida que el token no haya expirado.
 *
 * @param string $token Token de verificación
 * @return int|string|false User ID, 'expired' si el token venció, o false si no existe
 */
function starter_find_user_by_verify_token($token) {
    // Búsqueda directa en la DB para evitar problemas de object cache
    // get_users() con meta_query puede devolver resultados stale cuando hay
    // plugins de caché agresivos (W3 Total Cache, LiteSpeed, etc.)
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
        '_membership_verify_token',
        $token
    ));
    
    if (!$row) {
        return false;
    }
    
    $user_id = intval($row->user_id);
    
    // Verificar expiración directamente en DB (NO borrar el token aquí para que recargas sigan mostrando 'expired')
    $expires = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
        $user_id,
        '_membership_verify_token_expires'
    ));
    if (!$expires || intval($expires) <= time()) {
        return 'expired';
    }
    
    return $user_id;
}

/**
 * Endpoint autenticado: obtener el token de verificación del usuario actual.
 * Se usa en el frontend para generar el QR del carné digital.
 */
function starter_api_get_verify_token($request) {
    $user_id = get_current_user_id();
    
    // Solo generar token si la membresía está activa
    $membership = starter_memberships_get_user_membership($user_id);
    if (!$membership || $membership->status !== 'active') {
        return new WP_REST_Response([
            'success' => false,
            'code' => 'membership_inactive',
            'message' => 'No tienes una membresía activa'
        ], 403);
    }
    
    $token = starter_get_verification_token($user_id);
    
    return new WP_REST_Response([
        'success' => true,
        'data' => ['token' => $token]
    ], 200);
}

/**
 * Verificación pública de membresía (para QR del carné digital).
 * Acepta un token opaco (no el user ID).
 * Devuelve datos mínimos: nombre (parcial), estado, nivel.
 * NO expone email, cédula ni datos sensibles.
 */
function starter_api_verify_membership($request) {
    $token = sanitize_text_field($request->get_param('token'));
    
    $result = starter_find_user_by_verify_token($token);
    if ($result === 'expired') {
        return new WP_REST_Response([
            'success' => false,
            'verified' => false,
            'code' => 'token_expired',
            'message' => 'El código de verificación ha expirado'
        ], 410);
    }
    if (!$result) {
        return new WP_REST_Response([
            'success' => false,
            'verified' => false,
            'code' => 'not_found',
            'message' => 'Token de verificación inválido'
        ], 404);
    }
    $user_id = $result;
    
    $user = get_user_by('ID', $user_id);
    if (!$user) {
        return new WP_REST_Response([
            'success' => false,
            'verified' => false,
            'message' => 'Socio no encontrado'
        ], 404);
    }
    
    $membership = starter_memberships_get_user_membership($user_id);
    $is_active = $membership && $membership->status === 'active';
    
    // Nombre parcial por privacidad: "Jiménez S." (apellido + inicial del nombre)
    $first_name = $user->first_name ?: $user->display_name;
    $last_name = $user->last_name;
    if ($last_name) {
        $masked_name = $last_name;
        if ($first_name) {
            $masked_name .= ' ' . mb_substr($first_name, 0, 1) . '.';
        }
    } else {
        $masked_name = $first_name;
    }
    
    // Número de socio formateado
    $member_number = 'FI-' . str_pad($user_id, 5, '0', STR_PAD_LEFT);
    
    $response_data = [
        'verified' => $is_active,
        'member_number' => $member_number,
        'name' => $masked_name,
        'document_id' => starter_mask_document_id(get_user_meta($user_id, 'cedula', true)),
        'status' => $is_active ? 'active' : 'inactive',
        'member_since' => $membership ? $membership->start_date : null,
        'verified_at' => current_time('c'),
    ];
    
    return new WP_REST_Response([
        'success' => true,
        'data' => $response_data
    ], 200);
}

/**
 * Invalidar token de verificación al expirar la membresía.
 * Evita que QRs compartidos/screenshotted sigan resolviendo datos del socio.
 */
add_action('starter_membership_expired', function($user_id) {
    starter_invalidate_verification_token($user_id);
}, 10, 1);

add_action('starter_membership_cancelled', function($user_id) {
    starter_invalidate_verification_token($user_id);
}, 10, 1);

// Registrar endpoint de debug
add_action('rest_api_init', function() {
    register_rest_route('starter/v1', '/membership/debug/free-samples', [
        'methods' => 'GET',
        'callback' => 'starter_api_debug_free_samples',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});
