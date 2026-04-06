<?php
/**
 * Manejador para solicitar el restablecimiento de contraseña
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Procesa una solicitud de restablecimiento de contraseña
 * 
 * @param WP_REST_Request $request Datos de la solicitud
 * @return WP_REST_Response Respuesta con resultado de la solicitud
 */
function starter_request_password_reset($request) {
    // Configurar los encabezados CORS
    starter_password_reset_cors_headers();
    
    // Obtener parámetros
    $params = $request->get_params();
    
    // Validar parámetros requeridos
    if (!isset($params['email'])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Falta el parámetro requerido (email)'
        ], 400);
    }
    
    $email = sanitize_email($params['email']);
    
    // Validar formato de correo electrónico
    if (!is_email($email)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Formato de correo electrónico inválido'
        ], 400);
    }
    
    // Mensaje genérico para evitar enumeración de usuarios
    $generic_response = [
        'success' => true,
        'message' => 'Si el correo está registrado, recibirás instrucciones para restablecer tu contraseña.'
    ];
    
    // Buscar usuario por correo electrónico
    $user = get_user_by('email', $email);
    
    // Verificar si el usuario existe
    if (!$user) {
        error_log('Intento de restablecimiento de contraseña para correo no registrado: ' . $email);
        return new WP_REST_Response($generic_response, 200);
    }
    
    // Generar clave de restablecimiento
    $key = get_password_reset_key($user);
    
    if (is_wp_error($key)) {
        error_log('Error al generar clave de restablecimiento: ' . $key->get_error_message());
        
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Error al generar clave de restablecimiento'
        ], 500);
    }
    
    // Registrar el token en nuestra tabla personalizada
    $token_hash = wp_hash($key); // Crear un hash seguro del token
    $registered = starter_register_reset_token($token_hash, $user->ID, $user->user_login);
    
    if (!$registered) {
        error_log('Error al registrar token de restablecimiento para: ' . $email);
        // Continuamos de todos modos, ya que el token de WordPress sigue siendo válido
    }
    

    // Construir URL de restablecimiento usando el dominio configurado según el entorno
    $frontend_domain = defined('FRONTEND_URL') ? FRONTEND_URL : 'https://example.com';
    $reset_url = $frontend_domain . '/reset-password?key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login);

    // Enviar correo usando el template
    $mail_sent = starter_send_password_reset_email($email, $user->display_name, $reset_url);

    
    if (!$mail_sent) {
        error_log('Error al enviar correo de restablecimiento a: ' . $email);
        // IMPORTANTE: Retornar respuesta genérica para evitar enumeración de usuarios
        // No revelar si el email existe o no
        return new WP_REST_Response($generic_response, 200);
    }
    
    // Registrar la acción en el log
    error_log('Correo de restablecimiento enviado a: ' . $email);
    
    return new WP_REST_Response($generic_response, 200);
}