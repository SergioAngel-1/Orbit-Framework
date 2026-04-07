<?php
/**
 * JWT Authentication Middleware
 * 
 * Middleware centralizado para forzar la autenticación JWT en TODAS las peticiones REST API
 * antes de que se ejecuten los endpoints. Esto evita tener que repetir el código en cada endpoint.
 * 
 * @package Starter
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rutas públicas de la API Starter que NO requieren autenticación JWT.
 * 
 * Fuente de verdad única — usada por:
 * - Middleware de prioridad 5 (establecer usuario si hay token, pero no bloquear)
 * - Filtro de prioridad 11 (limpiar errores JWT en rutas públicas)
 * 
 * Patrón consistente con starter_get_csrf_protected_endpoints() en csrf-protection.php
 * 
 * @return array Lista de prefijos de rutas públicas (se comparan con strpos === 0)
 */
function starter_get_jwt_public_routes() {
    return array(
        '/starter/v1/auth',                    // Login
        '/starter/v1/register',                // Registro
        '/starter/v1/contact',                 // Formulario de contacto público
        '/starter/v1/request-password-reset',  // Solicitar reset de contraseña
        '/starter/v1/validate-password-reset', // Validar código de reset
        '/starter/v1/complete-password-reset', // Completar reset de contraseña
        '/starter/v1/referrals/validate-code', // Validar código de referido (público)
        '/starter/v1/legal',                   // Documentos legales (público)
        '/starter/v1/featured-categories',     // Categorías destacadas (público)
        '/starter/v1/banners',                 // Banners (público)
        '/starter/v1/hiperofertas',            // Hiperofertas (público)
        '/starter/v1/promotional-grid',        // Grid promocional (público)
        '/starter/v1/menu',                    // Menú (público)
        '/starter/v1/home-sections',           // Secciones de inicio (público con membresía opcional)
        '/starter/v1/reviews/',                // Reseñas de productos (GET público, POST protegido por permission_callback)
        '/starter/v1/reviews/listing',         // Listado general de reseñas (público)
        '/starter/v1/popups',                  // Popups dinámicos (público, interacción anónima permitida)
        '/starter/v1/product-categories',      // Categorías de productos (público)
        '/starter/v1/settings/minimum-order',  // Configuración de pedido mínimo (GET público)
        '/starter/v1/validate/',               // Validación de campos únicos: phone, cedula, email (público para registro)
        '/starter/v1/batch',                   // Batch requests (auth se verifica por solicitud individual)
        '/starter/v1/query',                   // Fields selection query (público)
        '/starter/v1/referrals/config',        // Configuración del programa de referidos (público)
        '/starter/v1/product/',                // Info de Virtual Coins por producto (público)
        '/starter/v1/system/config',           // Configuración pública del sistema de puntos
        '/starter/v1/newsletter/',             // Suscripción a newsletter (público)
        '/starter/v1/wompi/webhook',           // Webhook de Wompi (público, verificado por firma)
        '/starter/v1/reset-password',          // Reset de contraseña (ruta legacy, público)
    );
}

/**
 * Middleware global para resolver el usuario JWT en REST API
 * 
 * Se ejecuta ANTES de permission_callback y ANTES de CSRF validation (prioridad 5).
 * Su rol es ESTABLECER el usuario actual vía wp_set_current_user() si hay token JWT,
 * para que los permission_callback de cada endpoint puedan evaluar is_user_logged_in().
 * 
 * NOTA: Este middleware NO bloquea rutas sin token. La protección de acceso
 * la provee el permission_callback individual de cada endpoint.
 */
add_filter('rest_pre_dispatch', function($result, $server, $request) {
    // Si ya hay un error, no procesar
    if (is_wp_error($result)) {
        return $result;
    }
    
    // Obtener la ruta del request
    $route = $request->get_route();
    
    // Solo procesar rutas de nuestra API custom (/starter/v1/*)
    // Excluir rutas públicas que NO requieren autenticación
    $public_routes = starter_get_jwt_public_routes();
    
    // Para rutas públicas, intentar autenticar si hay token pero no bloquear si no hay
    $is_public_route = false;
    foreach ($public_routes as $public_route) {
        if (strpos($route, $public_route) === 0) {
            $is_public_route = true;
            break;
        }
    }
    
    // Si es ruta pública, intentar establecer usuario si hay token (para membresías)
    if ($is_public_route) {
        $user_id = apply_filters('determine_current_user', false);
        if ($user_id) {
            wp_set_current_user($user_id);
        }
        return $result;
    }
    
    // Si NO es una ruta de nuestra API custom, no procesar
    if (strpos($route, '/starter/v1/') !== 0) {
        return $result;
    }
    
    // Establecer usuario JWT para rutas protegidas de /starter/v1/*
    // La autenticación real la valida el permission_callback de cada endpoint
    $user_id = apply_filters('determine_current_user', false);
    if ($user_id) {
        wp_set_current_user($user_id);
    }
    
    return $result;
}, 5, 3); // Prioridad 5: ANTES de rate limiting (10) y CSRF (100)

/**
 * Protección de rutas públicas contra errores JWT
 * 
 * El plugin jwt-auth (prioridad 10) propaga errores jwt_auth_* a TODAS las
 * peticiones REST vía rest_pre_dispatch, incluyendo rutas públicas como
 * /banners y /home-sections. Cuando un JWT expira, esto causa un 403 global
 * que bloquea toda la app.
 * 
 * Este filtro (prioridad 11) se ejecuta justo DESPUÉS del plugin JWT y
 * limpia el error para rutas públicas, permitiendo que continúen como anónimas.
 */
add_filter('rest_pre_dispatch', function($result, $server, $request) {
    // Solo intervenir si hay un error JWT
    if (!is_wp_error($result)) {
        return $result;
    }
    
    $error_code = $result->get_error_code();
    
    // Solo limpiar errores del plugin JWT (jwt_auth_*)
    if (strpos($error_code, 'jwt_auth_') !== 0) {
        return $result;
    }
    
    $route = $request->get_route();
    
    // Rutas públicas de Starter (fuente única: starter_get_jwt_public_routes())
    $public_routes = starter_get_jwt_public_routes();
    
    // Rutas WooCommerce públicas (catálogo de productos, categorías)
    $wc_public_prefixes = array(
        '/wc/v3/products',
        '/wc/v3/products/categories',
        '/starter/v1/wc/products',
        '/starter/v1/wc/products/categories',
    );
    
    $is_public = false;
    foreach ($public_routes as $public_route) {
        if (strpos($route, $public_route) === 0) {
            $is_public = true;
            break;
        }
    }
    
    if (!$is_public) {
        foreach ($wc_public_prefixes as $prefix) {
            if (strpos($route, $prefix) === 0) {
                $is_public = true;
                break;
            }
        }
    }
    
    if ($is_public) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Starter JWT] Token inválido en ruta pública %s (code=%s), continuando como anónimo',
                $route,
                $error_code
            ));
        }
        // Limpiar el error: permitir que la ruta pública continúe como anónima
        return null;
    }
    
    return $result;
}, 11, 3); // Prioridad 11: justo DESPUÉS del plugin JWT (10)
