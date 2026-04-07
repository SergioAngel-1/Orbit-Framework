<?php
/**
 * Manejador para validar el token de restablecimiento de contraseña
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Valida un token de restablecimiento de contraseña
 * 
 * @param WP_REST_Request $request Datos de la solicitud
 * @return WP_REST_Response Respuesta con resultado de la validación
 */
function starter_validate_password_reset($request) {
    // Configurar los encabezados CORS
    starter_password_reset_cors_headers();
    
    // Obtener parámetros
    $params = $request->get_params();
    
    // Validar parámetros requeridos
    if (!isset($params['key']) || !isset($params['login'])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Faltan parámetros requeridos (key, login)'
        ], 400);
    }
    
    $key = sanitize_text_field($params['key']);
    $login = sanitize_text_field($params['login']);
    
    // Obtener el usuario por nombre de usuario o correo electrónico
    $user = get_user_by(strpos($login, '@') !== false ? 'email' : 'login', $login);
    
    if (!$user) {
        error_log("Usuario no encontrado para login: {$login}");
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Usuario no encontrado',
            'error_code' => 'invalid_user'
        ], 404);
    }
    
    // Registrar información de depuración (sin exponer el token)
    error_log("Validando token para usuario: {$login}");
    
    // Verificar en nuestro sistema personalizado SIN consumir el token
    $token_hash = wp_hash($key);
    $verify_result = starter_verify_reset_token($token_hash, $login);
    
    if (!is_wp_error($verify_result)) {
        // El token es válido en nuestro sistema
        error_log("Token validado desde sistema personalizado para usuario: {$login}");
    } else {
        // Token no encontrado o inválido en nuestro sistema
        $error_code = $verify_result->get_error_code();
        $error_message = 'Token de restablecimiento inválido o expirado';
        
        error_log("Error en validación de token para {$login}: {$error_code}");
        
        if ($error_code === 'expired_key') {
            $error_message = 'El enlace de restablecimiento ha expirado. Por favor, solicita uno nuevo.';
        } elseif ($error_code === 'invalid_key') {
            $error_message = 'El enlace de restablecimiento no es válido. Por favor, solicita uno nuevo.';
        }
        
        return new WP_REST_Response([
            'success' => false,
            'message' => $error_message,
            'error_code' => $error_code
        ], 400);
    }
    
    // El token es válido (no exponemos datos sensibles)
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Token válido'
    ], 200);
}