<?php
/**
 * API REST para el sistema de referidos
 * 
 * Funciones para los endpoints de la API REST relacionados con referidos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoint para obtener información del referidor por código
 */
function starter_rp_register_referrer_endpoint() {
    register_rest_route('starter-rp/v1', '/referrer/(?P<code>[a-zA-Z0-9\-]+)', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_referrer_by_code',
        'permission_callback' => '__return_true'
    ]);
}

/**
 * Obtener información del referidor por código
 * 
 * @param WP_REST_Request $request Objeto de solicitud
 * @return WP_REST_Response|WP_Error Respuesta o error
 */
function starter_rp_get_referrer_by_code($request) {
    $code = $request['code'];
    
    if (empty($code)) {
        return new WP_Error('invalid_code', 'Código de referido no válido', ['status' => 400]);
    }
    
    // Obtener ID del usuario por código
    $user_id = starter_rp_get_user_by_referral_code($code);
    
    if (!$user_id) {
        return new WP_Error('user_not_found', 'No se encontró usuario con ese código', ['status' => 404]);
    }
    
    // Obtener información del usuario
    $user = get_userdata($user_id);
    if (!$user) {
        return new WP_Error('user_data_error', 'Error al obtener datos del usuario', ['status' => 500]);
    }
    
    // Construir respuesta con información básica y segura
    $response = [
        'success' => true,
        'referrer' => [
            'id' => $user_id,
            'name' => $user->display_name
        ]
    ];
    
    return rest_ensure_response($response);
}
