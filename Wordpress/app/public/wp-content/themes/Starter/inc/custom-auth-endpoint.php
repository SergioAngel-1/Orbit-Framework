<?php
/**
 * Endpoint personalizado de autenticación
 * 
 * Este archivo crea un endpoint personalizado para la autenticación que funciona
 * como alternativa al plugin JWT Auth cuando éste no devuelve correctamente el token.
 */

// Asegurarse de que el archivo se está incluyendo desde WordPress
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar el endpoint personalizado de autenticación
 */
function starter_register_auth_endpoint() {
    register_rest_route('starter/v1', '/auth', array(
        'methods' => 'POST',
        'callback' => 'starter_custom_auth',
        'permission_callback' => function() {
            return true; // Permitir acceso público
        }
    ));
}
add_action('rest_api_init', 'starter_register_auth_endpoint');

/**
 * Función de autenticación personalizada
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function starter_custom_auth($request) {
    // Verificar que se haya configurado la clave secreta JWT
    $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;
    
    if (!$secret_key) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'JWT no está configurado correctamente, contacte al administrador',
        ], 403);
    }
    
    // Obtener credenciales
    $username = $request->get_param('username');
    $password = $request->get_param('password');
    
    if (empty($username) || empty($password)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Usuario y contraseña son requeridos',
        ], 400);
    }
    
    // Autenticar usuario
    $user = wp_authenticate($username, $password);
    
    // Verificar si hay errores
    if (is_wp_error($user)) {
        $error_code = $user->get_error_code();
        
        // Devolver 401 para errores de autenticación en lugar de 403
        return new WP_REST_Response([
            'success' => false,
            'message' => $user->get_error_message($error_code),
            'code' => $error_code
        ], 401);
    }
    
    // Verificar si el usuario está pendiente de aprobación
    $pending = get_user_meta($user->ID, 'pending_approval', true);
    if (!empty($pending)) {
        // Devolver 200 para usuarios pendientes, pero con un flag especial
        // Esto permite que el frontend lo maneje como un caso especial sin tratarlo como error
        return new WP_REST_Response([
            'success' => true, // Marcamos como exitoso
            'message' => 'Tu cuenta está pendiente de aprobación por un administrador.',
            'pending' => true,
            'user_id' => $user->ID,
            'user_email' => $user->user_email,
            'user_nicename' => $user->user_nicename,
            'user_display_name' => $user->display_name,
            'status' => 'pending'
        ], 200); // Código 200 OK
    }
    
    // Verificar si el usuario ha sido rechazado
    if (in_array('rejected', $user->roles)) {
        // Devolver 200 para usuarios rechazados, pero con un flag especial
        // Esto permite que el frontend lo maneje como un caso especial sin tratarlo como error
        return new WP_REST_Response([
            'success' => true, // Marcamos como exitoso para evitar errores HTTP
            'message' => 'Tu solicitud de cuenta ha sido rechazada. Por favor, contacta con el administrador para más información.',
            'rejected' => true,
            'user_id' => $user->ID,
            'user_email' => $user->user_email,
            'user_nicename' => $user->user_nicename,
            'user_display_name' => $user->display_name,
            'status' => 'rejected'
        ], 200); // Código 200 OK como en cuentas pendientes
    }
    
    // Generar token JWT
    $issuedAt = time();
    $notBefore = apply_filters('jwt_auth_not_before', $issuedAt, $issuedAt);
    $expire = apply_filters('jwt_auth_expire', $issuedAt + (DAY_IN_SECONDS * 2), $issuedAt);
    
    $token_data = [
        'iss' => get_bloginfo('url'),
        'iat' => $issuedAt,
        'nbf' => $notBefore,
        'exp' => $expire,
        'data' => [
            'user' => [
                'id' => $user->data->ID,
            ],
        ],
    ];
    
    // Cargar la biblioteca JWT si no está cargada
    if (!class_exists('Firebase\JWT\JWT')) {
        // Intentar cargar desde el plugin JWT Auth
        $jwt_auth_dir = WP_PLUGIN_DIR . '/jwt-authentication-for-wp-rest-api';
        if (file_exists($jwt_auth_dir . '/includes/vendor/autoload.php')) {
            require_once $jwt_auth_dir . '/includes/vendor/autoload.php';
        } else {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No se pudo cargar la biblioteca JWT',
            ], 500);
        }
    }
    
    try {
        // Generar token
        $token = Firebase\JWT\JWT::encode(
            apply_filters('jwt_auth_token_before_sign', $token_data, $user),
            $secret_key,
            'HS256'
        );
        
        // Generar CSRF token para protección adicional
        $prev_user_id = get_current_user_id();
        wp_set_current_user($user->data->ID);
        $csrf_token = wp_create_nonce('starter_api_' . $user->data->ID);
        wp_set_current_user($prev_user_id);
        
        // Preparar respuesta
        $response_data = [
            'success' => true,
            'token' => $token,
            'csrf_token' => $csrf_token,
            'user_id' => $user->data->ID,
            'user_email' => $user->data->user_email,
            'user_nicename' => $user->data->user_nicename,
            'user_display_name' => $user->data->display_name,
        ];
        
        // Aplicar filtro para permitir modificaciones
        $response_data = apply_filters('starter_auth_response', $response_data, $user);
        
        // Devolver respuesta
        return new WP_REST_Response($response_data, 200);
    } catch (Exception $e) {
        return new WP_REST_Response([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
}

/**
 * NOTA: La configuración CORS ha sido centralizada en cors-functions.php
 * Este endpoint ya no necesita configurar CORS manualmente.
 * 
 * @see inc/cors-functions.php Para la configuración CORS unificada
 */
