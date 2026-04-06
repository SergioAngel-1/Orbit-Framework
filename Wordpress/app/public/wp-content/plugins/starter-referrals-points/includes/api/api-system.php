<?php
/**
 * API endpoints para estado del sistema
 * 
 * Endpoints para verificar configuraciones y estado del sistema.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener estado del sistema para el usuario actual
 * 
 * @return WP_REST_Response|WP_Error
 */
function starter_rp_get_system_status_endpoint() {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error(
            'not_logged_in',
            'Debes estar logueado para acceder a esta información.',
            ['status' => 401]
        );
    }
    
    // Obtener permisos del usuario
    $permissions = starter_rp_get_user_system_permissions($user_id);
    
    // Obtener configuraciones generales
    $options = Starter_RP()->get_options();
    
    $response = [
        'systems' => [
            'points_enabled' => (bool) ($options['enable_points'] ?? 1),
            'referrals_enabled' => (bool) ($options['enable_referrals'] ?? 1),
        ],
        'user_permissions' => [
            'can_use_points' => $permissions['can_use_points'],
            'can_use_referrals' => $permissions['can_use_referrals'],
            'user_role' => $permissions['user_role'],
            'allowed_roles' => $options['allowed_roles'] ?? ['customer'],
        ],
        'configuration' => [
            'points_conversion_rate' => (float) $options['points_conversion_rate'],
            'points_percentage' => (float) ($options['points_percentage'] ?? 5),
            'min_points_redemption' => (int) ($options['min_points_redemption'] ?? 100),
            'max_points_per_order' => (int) ($options['max_points_per_order'] ?? 0),
            'points_expiry_days' => (int) ($options['points_expiry_days'] ?? 365),
            'point_triggers' => $options['point_triggers'] ?? ['purchase'],
            'points_registration' => (int) ($options['points_registration'] ?? 100),
            'points_review' => (int) ($options['points_review'] ?? 50),
            'points_birthday' => (int) ($options['points_birthday'] ?? 200),
            // Comisiones de referidos personalizadas según nivel de membresía del usuario
            'referral_commission_first' => function_exists('starter_rp_get_commission_for_user') 
                ? (float) starter_rp_get_commission_for_user($user_id, 'first', $options) 
                : (float) ($options['referral_commission_first'] ?? 10),
            'referral_commission_subsequent' => function_exists('starter_rp_get_commission_for_user') 
                ? (float) starter_rp_get_commission_for_user($user_id, 'subsequent', $options) 
                : (float) ($options['referral_commission_subsequent'] ?? 5),
            'referral_commission_duration' => (int) ($options['referral_commission_duration'] ?? 365),
            'enable_second_level' => (bool) ($options['enable_second_level'] ?? false),
            'second_level_commission' => function_exists('starter_rp_get_commission_for_user') 
                ? (float) starter_rp_get_commission_for_user($user_id, 'level2', $options) 
                : (float) ($options['second_level_commission'] ?? 2),
            'signup_points_level1' => (int) ($options['signup_points_level1'] ?? 100),
            'signup_points_level2' => (int) ($options['signup_points_level2'] ?? 50),
        ],
        'messages' => []
    ];
    
    // Agregar mensajes informativos
    if (!$response['systems']['points_enabled']) {
        $response['messages'][] = [
            'type' => 'info',
            'code' => 'points_disabled',
            'message' => 'El sistema de Virtual Coins está deshabilitado por el administrador.'
        ];
    }
    
    if (!$response['systems']['referrals_enabled']) {
        $response['messages'][] = [
            'type' => 'info',
            'code' => 'referrals_disabled',
            'message' => 'El programa de referidos está deshabilitado por el administrador.'
        ];
    }
    
    if (!$permissions['can_use_points'] && $response['systems']['points_enabled']) {
        $response['messages'][] = [
            'type' => 'warning',
            'code' => 'points_role_restricted',
            'message' => 'Su rol de usuario no tiene permisos para usar el sistema de Virtual Coins.'
        ];
    }
    
    if (!$permissions['can_use_referrals'] && $response['systems']['referrals_enabled']) {
        $response['messages'][] = [
            'type' => 'warning',
            'code' => 'referrals_role_restricted',
            'message' => 'Su rol de usuario no tiene permisos para participar en el programa de referidos.'
        ];
    }
    
    return rest_ensure_response($response);
}

/**
 * Obtener configuración pública del sistema
 * 
 * @return WP_REST_Response
 */
function starter_rp_get_public_system_config_endpoint() {
    $options = Starter_RP()->get_options();
    
    // Obtener configuración de aprobación automática del tema
    $auto_approval_enabled = function_exists('starter_is_auto_approval_enabled') 
        ? starter_is_auto_approval_enabled() 
        : false;
    
    // Solo exponer datos necesarios para el frontend público.
    // Datos internos sensibles (comisiones exactas, límites, triggers) se sirven
    // solo a usuarios autenticados vía /system/status o /referrals/config.
    $response = [
        'systems' => [
            'points_enabled' => (bool) ($options['enable_points'] ?? 1),
            'referrals_enabled' => (bool) ($options['enable_referrals'] ?? 1),
        ],
        'registration' => [
            'auto_approval_enabled' => $auto_approval_enabled,
        ],
        'configuration' => [
            'points_conversion_rate' => (float) $options['points_conversion_rate'],
            'points_percentage' => (float) ($options['points_percentage'] ?? 5),
            'min_points_redemption' => (int) ($options['min_points_redemption'] ?? 100),
            'max_points_per_order' => (int) ($options['max_points_per_order'] ?? 0),
            'points_expiry_days' => (int) ($options['points_expiry_days'] ?? 365),
            'point_triggers' => $options['point_triggers'] ?? ['purchase'],
            'points_registration' => (int) ($options['points_registration'] ?? 100),
            'points_review' => (int) ($options['points_review'] ?? 50),
            'points_birthday' => (int) ($options['points_birthday'] ?? 200),
            'signup_points_level1' => (int) ($options['signup_points_level1'] ?? 100),
            'signup_points_level2' => (int) ($options['signup_points_level2'] ?? 50),
            'display_points_checkout' => (bool) ($options['display_points_checkout'] ?? 1),
            'redeem_points_text' => sanitize_text_field($options['redeem_points_text'] ?? 'Usar mis Virtual Coins disponibles ({points} puntos)'),
            'insufficient_points_text' => sanitize_text_field($options['insufficient_points_text'] ?? 'Necesitas al menos {min_points} Virtual Coins para canjear. Tienes {current_points} FC.'),
            'discount_applied_text' => sanitize_text_field($options['discount_applied_text'] ?? 'Descuento de {points} Virtual Coins aplicado (-{discount})'),
        ]
    ];

    // Solo incluir comisiones de referidos si el usuario está autenticado
    if (is_user_logged_in()) {
        $response['configuration']['referral_commission_first'] = (float) ($options['referral_commission_first'] ?? 10);
        $response['configuration']['referral_commission_subsequent'] = (float) ($options['referral_commission_subsequent'] ?? 5);
        $response['configuration']['referral_commission_duration'] = (int) ($options['referral_commission_duration'] ?? 365);
        $response['configuration']['enable_second_level'] = (bool) ($options['enable_second_level'] ?? false);
        $response['configuration']['second_level_commission'] = (float) ($options['second_level_commission'] ?? 2);
    }
    
    return rest_ensure_response($response);
}

/**
 * Validar configuración del sistema (endpoint para administradores)
 * 
 * @return WP_REST_Response|WP_Error
 */
function starter_rp_validate_system_config_endpoint() {
    if (!current_user_can('manage_options')) {
        return new WP_Error(
            'insufficient_permissions',
            'No tienes permisos para acceder a esta información.',
            ['status' => 403]
        );
    }
    
    $options = Starter_RP()->get_options();
    $issues = [];
    
    // Validar configuración de puntos
    if ($options['points_conversion_rate'] <= 0) {
        $issues[] = [
            'type' => 'error',
            'field' => 'points_conversion_rate',
            'message' => 'La tasa de conversión de puntos debe ser mayor a 0.'
        ];
    }
    
    if ($options['points_percentage'] <= 0) {
        $issues[] = [
            'type' => 'error',
            'field' => 'points_percentage',
            'message' => 'El porcentaje de puntos por compra debe ser mayor a 0.'
        ];
    }
    
    // Validar roles permitidos
    if (empty($options['allowed_roles'])) {
        $issues[] = [
            'type' => 'warning',
            'field' => 'allowed_roles',
            'message' => 'No hay roles permitidos configurados. Ningún usuario podrá usar el sistema.'
        ];
    }
    
    // Validar configuración de eventos de puntos
    $point_triggers = $options['point_triggers'] ?? ['purchase'];
    if (empty($point_triggers)) {
        $issues[] = [
            'type' => 'warning',
            'field' => 'point_triggers',
            'message' => 'No hay eventos configurados para otorgar puntos.'
        ];
    }
    
    // Validar cantidades de puntos por eventos
    if (in_array('registration', $point_triggers) && ($options['points_registration'] ?? 0) <= 0) {
        $issues[] = [
            'type' => 'warning',
            'field' => 'points_registration',
            'message' => 'El evento de registro está habilitado pero no otorga puntos.'
        ];
    }
    
    if (in_array('review', $point_triggers) && ($options['points_review'] ?? 0) <= 0) {
        $issues[] = [
            'type' => 'warning',
            'field' => 'points_review',
            'message' => 'El evento de reseñas está habilitado pero no otorga puntos.'
        ];
    }
    
    if (in_array('birthday', $point_triggers) && ($options['points_birthday'] ?? 0) <= 0) {
        $issues[] = [
            'type' => 'warning',
            'field' => 'points_birthday',
            'message' => 'El evento de cumpleaños está habilitado pero no otorga puntos.'
        ];
    }
    
    // Validar mínimo de puntos para canjear
    if (($options['min_points_redemption'] ?? 0) <= 0) {
        $issues[] = [
            'type' => 'warning',
            'field' => 'min_points_redemption',
            'message' => 'El mínimo de puntos para canjear debe ser mayor a 0.'
        ];
    }
    
    $response = [
        'valid' => empty($issues),
        'issues' => $issues,
        'recommendations' => []
    ];
    
    // Agregar recomendaciones
    if ($options['max_points_per_order'] == 0) {
        $response['recommendations'][] = [
            'type' => 'suggestion',
            'message' => 'Considere establecer un límite máximo de puntos por pedido para evitar abuso del sistema.'
        ];
    }
    
    if ($options['points_expiry_days'] == 0) {
        $response['recommendations'][] = [
            'type' => 'suggestion',
            'message' => 'Los puntos sin fecha de expiración pueden acumularse indefinidamente.'
        ];
    }
    
    // Recomendaciones específicas para Virtual Coins
    if (($options['points_conversion_rate'] ?? 0) < 0.01) {
        $response['recommendations'][] = [
            'type' => 'suggestion',
            'message' => 'Una tasa de conversión muy baja puede hacer que los puntos tengan poco valor percibido.'
        ];
    }
    
    if (($options['min_points_redemption'] ?? 0) > 1000) {
        $response['recommendations'][] = [
            'type' => 'suggestion',
            'message' => 'Un mínimo muy alto para canjear puede desincentivar la participación.'
        ];
    }
    
    // Verificar si solo las compras otorgan puntos
    $point_triggers = $options['point_triggers'] ?? ['purchase'];
    if (count($point_triggers) === 1 && in_array('purchase', $point_triggers)) {
        $response['recommendations'][] = [
            'type' => 'suggestion',
            'message' => 'Considera habilitar eventos adicionales (registro, reseñas) para aumentar la participación.'
        ];
    }
    
    return rest_ensure_response($response);
} 