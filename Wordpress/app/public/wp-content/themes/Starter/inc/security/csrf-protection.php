<?php
/**
 * CSRF Protection for Starter API
 * 
 * Protege endpoints críticos contra ataques CSRF (Cross-Site Request Forgery)
 * mediante validación de tokens nonce de WordPress.
 * 
 * @package Starter
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoints que requieren protección CSRF
 * Estos son endpoints que realizan operaciones críticas (escritura, modificación, eliminación)
 */
function starter_get_csrf_protected_endpoints() {
    return array(
        // WooCommerce Orders (crear, actualizar)
        '/starter/v1/wc/orders',
        
        // Puntos y transferencias
        '/starter/v1/points/transfer',
        '/starter/v1/points/redeem',
        
        // Perfil de usuario (actualización) - SOLO custom endpoints
        '/starter/v1/user/profile',
        '/starter/v1/user/addresses',
        
        // Carrito (operaciones críticas - requieren autenticación)
        '/starter/v1/cart',
        '/starter/v1/cart/sync',
        '/starter/v1/cart/clear',
        
        // Contacto y emails (autenticados)
        '/starter/v1/order-email',
        
        // Reseñas de productos (crear reseña, responder)
        '/starter/v1/reviews',
        
        // Wompi — Pagos y compras (operaciones financieras críticas)
        '/starter/v1/wompi/signature',
        '/starter/v1/virtual-coins/pending-purchase',
        '/starter/v1/virtual-coins/confirm-purchase',
        '/starter/v1/membership/pending-purchase',
        '/starter/v1/membership/confirm-purchase',
        '/starter/v1/checkout/card-payment/pending',
        '/starter/v1/checkout/card-payment/confirm',
        '/starter/v1/checkout/card-payment/link-order',
        
        // NOTA: /wompi/webhook NO requiere CSRF (viene de servidores Wompi, no del frontend)
        
        // NOTA: Los siguientes endpoints NO requieren CSRF porque no requieren autenticación:
        // - /starter/v1/contact (formulario público)
        // - /starter/v1/request-password-reset (público)
        // - /starter/v1/validate-password-reset (público)
        // - /starter/v1/complete-password-reset (público)
        // - /starter/v1/auth (login - no tiene sesión previa)
        // - /starter/v1/register (registro - no tiene sesión previa)
        // - /wp/v2/users/me (GET request - solo lectura, no necesita CSRF)
        // - /starter/v1/csrf/refresh (necesario para refrescar CSRF expirado)
    );
}

/**
 * Validar CSRF token en requests
 * 
 * @param WP_REST_Request $request Request object
 * @return bool|WP_Error True si válido, WP_Error si inválido
 */
function starter_validate_csrf_token($request) {
    // Obtener el token CSRF del header
    $csrf_token = $request->get_header('X-CSRF-Token');
    
    if (empty($csrf_token)) {
        return new WP_Error(
            'csrf_token_missing',
            'Token CSRF requerido. Por favor, inicia sesión nuevamente.',
            array('status' => 403)
        );
    }
    
    // Obtener el user ID actual
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error(
            'csrf_not_authenticated',
            'Debes estar autenticado para realizar esta acción.',
            array('status' => 401)
        );
    }
    
    // Verificar el nonce
    $nonce_action = 'starter_api_' . $user_id;
    $is_valid = wp_verify_nonce($csrf_token, $nonce_action);
    
    if (!$is_valid) {
        // Log del intento fallido (posible ataque CSRF)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                'CSRF: Token inválido para user_id=%d, IP=%s, route=%s',
                $user_id,
                starter_get_client_ip(),
                $request->get_route()
            ));
        }
        
        return new WP_Error(
            'csrf_token_invalid',
            'Token CSRF inválido o expirado. Por favor, recarga la página e intenta nuevamente.',
            array('status' => 403)
        );
    }
    
    return true;
}

/**
 * Middleware para validar CSRF en endpoints protegidos
 * Se ejecuta DESPUÉS de la autenticación JWT usando determine_current_user
 */
add_filter('rest_pre_dispatch', function($result, $server, $request) {
    // Si ya hay un error previo (ej. rate limiting 429), no procesar CSRF
    if ($result !== null) {
        return $result;
    }
    
    // Solo validar en requests POST, PUT, PATCH, DELETE
    $method = $request->get_method();
    if (!in_array($method, array('POST', 'PUT', 'PATCH', 'DELETE'))) {
        return $result;
    }
    
    // Obtener la ruta del request
    $route = $request->get_route();
    $effective_route = $route;
    if (strpos($route, '/starter/v1/wc/') === 0) {
        $proxy_route = $request->get_param('route');
        if (is_string($proxy_route) && $proxy_route !== '') {
            $effective_route = '/starter/v1/wc/' . ltrim($proxy_route, '/');
        }
    }
    
    // EXCEPCIÓN: /csrf/refresh NO requiere CSRF válido (paradoja lógica)
    if ($route === '/starter/v1/csrf/refresh') {
        return $result;
    }
    
    // Verificar si la ruta requiere protección CSRF
    $protected_endpoints = starter_get_csrf_protected_endpoints();
    $requires_csrf = false;
    
    foreach ($protected_endpoints as $protected_route) {
        // Usar strpos para permitir rutas con parámetros (ej: /orders/123)
        if (strpos($effective_route, $protected_route) === 0) {
            $requires_csrf = true;
            break;
        }
    }
    
    // Si no requiere CSRF, continuar normalmente
    if (!$requires_csrf) {
        return $result;
    }

    // Obtener el user_id (ya procesado por JWT middleware en prioridad 5)
    $current_user_id = get_current_user_id();
    
    // Si NO hay usuario autenticado, rechazar inmediatamente
    // No dejar pasar al endpoint para evitar bypass de CSRF
    if (!$current_user_id) {
        return new WP_Error(
            'csrf_authentication_required',
            'Autenticación requerida para este endpoint protegido.',
            array('status' => 401)
        );
    }
    
    // Validar el token CSRF
    $validation_result = starter_validate_csrf_token($request);
    
    // Si la validación falla, devolver el error
    if (is_wp_error($validation_result)) {
        return $validation_result;
    }
    
    // Si todo está bien, continuar con el request
    return $result;
}, 100, 3); // Prioridad 100 para ejecutar DESPUÉS de la autenticación JWT (que usa prioridad 10)

/**
 * Agregar header CSRF token a respuestas de autenticación
 * Esto permite al frontend saber que debe enviar CSRF tokens
 */
add_filter('rest_post_dispatch', function($response, $server, $request) {
    $route = $request->get_route();
    
    // Solo en endpoints de autenticación
    if (in_array($route, array('/starter/v1/auth', '/jwt-auth/v1/token'))) {
        $response->header('X-CSRF-Required', 'true');
    }
    
    return $response;
}, 10, 3);

/**
 * Endpoint para refrescar CSRF token sin re-autenticar
 * Útil cuando el nonce expira pero el JWT sigue válido
 * 
 * IMPORTANTE: Usa GET en lugar de POST para evitar paradoja lógica
 * (no puede requerir CSRF válido para refrescar CSRF)
 */
add_action('rest_api_init', function() {
    register_rest_route('starter/v1', '/csrf/refresh', array(
        'methods' => 'GET',
        'callback' => 'starter_refresh_csrf_token',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
});

function starter_refresh_csrf_token($request) {
    $current_user_id = get_current_user_id();
    
    if (!$current_user_id) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'No autenticado. Por favor, inicia sesión nuevamente.'
        ], 401);
    }
    
    // Generar nuevo CSRF token
    $csrf_token = wp_create_nonce('starter_api_' . $current_user_id);
    
    return new WP_REST_Response([
        'success' => true,
        'csrf_token' => $csrf_token,
        'message' => 'Token CSRF refrescado exitosamente'
    ], 200);
}
