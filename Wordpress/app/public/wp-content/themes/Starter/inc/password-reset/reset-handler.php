<?php
/**
 * Manejador para completar el restablecimiento de contraseña
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Completa el proceso de restablecimiento de contraseña
 * 
 * @param WP_REST_Request $request Datos de la solicitud
 * @return WP_REST_Response Respuesta con resultado del proceso
 */
function starter_complete_password_reset($request) {
    // Configurar los encabezados CORS
    starter_password_reset_cors_headers();
    
    // Registrar solicitud
    error_log("Solicitud de completar restablecimiento de contraseña recibida");
    
    // Obtener parámetros
    $params = $request->get_params();
    
    // Validar parámetros requeridos
    if (!isset($params['key']) || !isset($params['login']) || !isset($params['password'])) {
        error_log("Error: Faltan parámetros requeridos en la solicitud de restablecimiento");
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Faltan parámetros requeridos (key, login, password)',
            'error_code' => 'missing_params'
        ], 400);
    }
    
    $key = sanitize_text_field($params['key']);
    $login = sanitize_text_field($params['login']);
    $password = $params['password']; // No sanitizar la contraseña
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Procesando restablecimiento para usuario: {$login}");
    }

    // Verificar la fortaleza de la contraseña
    $password_strength = starter_check_password_strength($password);
    if ($password_strength['strength'] < 3) {
        error_log("Error: Contraseña no lo suficientemente segura para usuario: {$login} - Fortaleza: {$password_strength['strength']}");
        return new WP_REST_Response([
            'success' => false,
            'message' => 'La contraseña no es lo suficientemente segura. ' . $password_strength['message'],
            'error_code' => 'weak_password',
            'strength' => $password_strength['strength']
        ], 400);
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Contraseña cumple requisitos de seguridad para usuario: {$login}");
    }
    
    // Obtener el usuario por nombre de usuario o correo electrónico
    $user = get_user_by(strpos($login, '@') !== false ? 'email' : 'login', $login);
    
    if (!$user) {
        error_log("Error: Usuario no encontrado: {$login}");
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Usuario no encontrado',
            'error_code' => 'user_not_found'
        ], 404);
    }
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("Usuario encontrado: {$login} (ID: {$user->ID})");
    }
    
    // Verificar token en nuestro sistema personalizado
    $token_hash = wp_hash($key);
    
    $verify_result = starter_verify_reset_token($token_hash, $login);
    
    if (is_wp_error($verify_result)) {
        // Token inválido o expirado
        $error_code = $verify_result->get_error_code();
        $error_message = 'Token de restablecimiento inválido o expirado';
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Error en validación de token para {$login}: {$error_code}");
        }
        
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
    
    // Token válido - usar el objeto de usuario que ya tenemos
    $user_obj = $user;
    
    try {
        // Cambiar la contraseña
        reset_password($user_obj, $password);
        
        // Invalidar el token en nuestro sistema personalizado
        starter_invalidate_reset_token($token_hash, $login);
        
        // Registrar la acción en el log
        error_log('Contraseña restablecida correctamente para el usuario: ' . $login);
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Contraseña restablecida correctamente'
        ], 200);
    } catch (Exception $e) {
        error_log("Error al cambiar contraseña para usuario {$login}: " . $e->getMessage());
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Error al cambiar la contraseña: ' . $e->getMessage(),
            'error_code' => 'password_reset_error'
        ], 500);
    }
}