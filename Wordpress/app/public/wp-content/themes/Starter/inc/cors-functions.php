<?php
/**
 * CORS Control Functions - CONFIGURACIÓN UNIFICADA
 * 
 * Este archivo centraliza TODA la configuración CORS para la API REST de WordPress.
 * Reemplaza las configuraciones duplicadas en jwt-auth-cors-fix.php y custom-auth-endpoint.php
 * 
 * @package Starter
 * @version 2.0.0
 * @since 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuración centralizada de orígenes permitidos
 * Filtra automáticamente según el entorno (producción vs desarrollo)
 * 
 * @return array Lista de orígenes permitidos según el entorno
 */
function starter_get_allowed_origins() {
    $is_prod = defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production';
    $origins = array();
    
    // 1. Leer origins explícitos desde wp-config.php (comma-separated)
    //    define('ALLOWED_CORS_ORIGINS', 'https://mystore.com,https://www.mystore.com');
    if (defined('ALLOWED_CORS_ORIGINS') && !empty(ALLOWED_CORS_ORIGINS)) {
        $explicit = array_map('trim', explode(',', ALLOWED_CORS_ORIGINS));
        $origins = array_merge($origins, $explicit);
    }
    
    // 2. Fallback: derivar origins del frontend URL configurado en Site Settings
    if (empty($origins) && function_exists('site_get_option')) {
        $frontend_url = site_get_option('frontend_url', '');
        if (!empty($frontend_url)) {
            $origins[] = rtrim($frontend_url, '/');
            // Añadir variante www si no la tiene, o sin www si la tiene
            $parsed = parse_url($frontend_url);
            if (isset($parsed['host'])) {
                $scheme = $parsed['scheme'] ?? 'https';
                if (strpos($parsed['host'], 'www.') === 0) {
                    $origins[] = $scheme . '://' . substr($parsed['host'], 4);
                } else {
                    $origins[] = $scheme . '://www.' . $parsed['host'];
                }
            }
        }
    }
    
    // 3. Fallback: HEADLESS_MODE_CLIENT_URL (legacy)
    if (empty($origins) && defined('HEADLESS_MODE_CLIENT_URL')) {
        $origins[] = rtrim(HEADLESS_MODE_CLIENT_URL, '/');
    }
    
    // 4. Añadir WP_HOME/WP_SITEURL para admin requests
    if (defined('WP_HOME')) {
        $origins[] = rtrim(WP_HOME, '/');
    }
    
    // 5. En desarrollo, añadir localhost automáticamente
    if (!$is_prod) {
        $dev_origins = array(
            'http://localhost:5173',
            'http://localhost:5174',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
        );
        // Añadir dominio local del admin si existe
        if (defined('WP_HOME') && strpos(WP_HOME, '.local') !== false) {
            $dev_origins[] = str_replace('https://', 'http://', WP_HOME);
            $dev_origins[] = WP_HOME;
        }
        $origins = array_merge($origins, $dev_origins);
    }
    
    return array_unique(array_filter($origins));
}

/**
 * Función principal unificada para manejar CORS
 * Aplica a todas las solicitudes a /wp-json/
 */
function starter_unified_cors_headers() {
    // Solo procesar solicitudes a la API REST
    // Verificar tanto /wp-json/ como ?rest_route= (URL alternativa de WP REST API)
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $is_rest = strpos($request_uri, '/wp-json/') !== false 
               || strpos($request_uri, 'rest_route=') !== false;
    if (!$is_rest) {
        return;
    }
    
    // Obtener el origen de la solicitud
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    $allowed_origins = starter_get_allowed_origins();

    // Validar origen contra whitelist
    if (!empty($origin) && in_array($origin, $allowed_origins, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        
        // Solo exponer métodos y headers a orígenes validados
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-WP-Nonce, X-Idempotency-Key, X-CSRF-Token, Accept');
        header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, X-WP-Total, X-WP-TotalPages');
        header('Access-Control-Max-Age: 86400');
    } else if (!empty($origin)) {
        // Log de intentos de acceso desde orígenes no autorizados (solo en desarrollo)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("CORS: Origen no autorizado bloqueado: $origin");
        }
    }
    
    // Manejar solicitudes OPTIONS (preflight)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        if (!empty($origin) && in_array($origin, $allowed_origins, true)) {
            header('Content-Length: 0');
            header('Content-Type: text/plain');
            status_header(204);
        } else {
            status_header(403);
        }
        exit();
    }
}

// Registrar en múltiples hooks para máxima cobertura
add_action('init', 'starter_unified_cors_headers', 1);
add_action('rest_api_init', 'starter_unified_cors_headers', 1);
add_action('parse_request', 'starter_unified_cors_headers', 1);

/**
 * Filtro para JWT Auth - asegurar CORS en tokens
 */
add_filter('jwt_auth_token_before_dispatch', function($data, $user) {
    starter_unified_cors_headers();
    return $data;
}, 1, 2);

/**
 * Filtro para todas las respuestas REST - aplicar CORS
 */
add_filter('rest_pre_serve_request', function($served, $result, $request) {
    starter_unified_cors_headers();
    return $served;
}, 1, 3);

/**
 * Permitir headers personalizados en la API REST
 */
add_filter('rest_allowed_cors_headers', function($allow_headers) {
    return array(
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-WP-Nonce',
        'X-CSRF-Token',
        'Accept',
        'X-Idempotency-Key'
    );
});

