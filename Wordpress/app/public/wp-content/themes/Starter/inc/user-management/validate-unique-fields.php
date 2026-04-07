<?php
/**
 * Validación de campos únicos para registro
 * 
 * Endpoints para validar que teléfono y cédula no estén ya registrados
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoints de validación de campos únicos
 */
function starter_register_unique_validation_endpoints() {
    // Endpoint para validar teléfono único
    register_rest_route('starter/v1', '/validate/phone', array(
        'methods' => 'POST',
        'callback' => 'starter_validate_unique_phone',
        'permission_callback' => '__return_true' // Público para registro
    ));
    
    // Endpoint para validar cédula única
    register_rest_route('starter/v1', '/validate/cedula', array(
        'methods' => 'POST',
        'callback' => 'starter_validate_unique_cedula',
        'permission_callback' => '__return_true' // Público para registro
    ));
    
    // Endpoint para validar email único
    register_rest_route('starter/v1', '/validate/email', array(
        'methods' => 'POST',
        'callback' => 'starter_validate_unique_email',
        'permission_callback' => '__return_true' // Público para registro
    ));
}
add_action('rest_api_init', 'starter_register_unique_validation_endpoints');

/**
 * Validar que el teléfono no esté registrado
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function starter_validate_unique_phone($request) {
    $phone = isset($request['phone']) ? sanitize_text_field($request['phone']) : '';
    
    if (empty($phone)) {
        return new WP_Error('missing_phone', 'El teléfono es requerido', array('status' => 400));
    }
    
    // Normalizar el teléfono (eliminar espacios y caracteres especiales excepto +)
    $normalized_phone = preg_replace('/[^0-9+]/', '', $phone);
    
    // Buscar en user_meta por 'phone' o 'billing_phone'
    global $wpdb;
    
    $existing_user = $wpdb->get_var($wpdb->prepare("
        SELECT user_id FROM {$wpdb->usermeta} 
        WHERE (meta_key = 'phone' OR meta_key = 'billing_phone')
        AND (meta_value = %s OR REPLACE(REPLACE(meta_value, ' ', ''), '-', '') = %s)
        LIMIT 1
    ", $phone, $normalized_phone));
    
    if ($existing_user) {
        return new WP_REST_Response(array(
            'is_unique' => false,
            'message' => 'Este número de teléfono ya está registrado en otra cuenta'
        ), 200);
    }
    
    return new WP_REST_Response(array(
        'is_unique' => true,
        'message' => 'Teléfono disponible'
    ), 200);
}

/**
 * Validar que la cédula no esté registrada
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function starter_validate_unique_cedula($request) {
    $cedula = isset($request['cedula']) ? sanitize_text_field($request['cedula']) : '';
    
    if (empty($cedula)) {
        return new WP_Error('missing_cedula', 'La cédula es requerida', array('status' => 400));
    }
    
    // Normalizar la cédula (eliminar puntos, guiones y espacios)
    $normalized_cedula = preg_replace('/[^0-9]/', '', $cedula);
    
    // Buscar en user_meta por 'cedula' o 'billing_cedula'
    global $wpdb;
    
    $existing_user = $wpdb->get_var($wpdb->prepare("
        SELECT user_id FROM {$wpdb->usermeta} 
        WHERE (meta_key = 'cedula' OR meta_key = 'billing_cedula' OR meta_key = 'documento_identidad')
        AND (meta_value = %s OR REPLACE(REPLACE(REPLACE(meta_value, '.', ''), '-', ''), ' ', '') = %s)
        LIMIT 1
    ", $cedula, $normalized_cedula));
    
    if ($existing_user) {
        return new WP_REST_Response(array(
            'is_unique' => false,
            'message' => 'Esta cédula ya está registrada en otra cuenta'
        ), 200);
    }
    
    return new WP_REST_Response(array(
        'is_unique' => true,
        'message' => 'Cédula disponible'
    ), 200);
}

/**
 * Validar que el email no esté registrado
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function starter_validate_unique_email($request) {
    $email = isset($request['email']) ? sanitize_email($request['email']) : '';
    
    if (empty($email)) {
        return new WP_Error('missing_email', 'El correo electrónico es requerido', array('status' => 400));
    }
    
    // Validar formato de email
    if (!is_email($email)) {
        return new WP_REST_Response(array(
            'is_unique' => false,
            'message' => 'El formato del correo electrónico no es válido'
        ), 200);
    }
    
    // Verificar si el email ya existe usando la función nativa de WordPress
    if (email_exists($email)) {
        return new WP_REST_Response(array(
            'is_unique' => false,
            'message' => 'Este correo electrónico ya está registrado en otra cuenta'
        ), 200);
    }
    
    return new WP_REST_Response(array(
        'is_unique' => true,
        'message' => 'Correo electrónico disponible'
    ), 200);
}

/**
 * Validar formato de cédula colombiana
 * 
 * @param string $cedula
 * @return bool
 */
function starter_validate_cedula_format($cedula) {
    // Normalizar: eliminar puntos, guiones y espacios
    $normalized = preg_replace('/[^0-9]/', '', $cedula);
    
    // La cédula colombiana tiene entre 6 y 10 dígitos
    if (strlen($normalized) < 6 || strlen($normalized) > 10) {
        return false;
    }
    
    // Debe ser solo números
    if (!ctype_digit($normalized)) {
        return false;
    }
    
    return true;
}
