<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Configurar headers de seguridad para WordPress backend
 * 
 * IMPORTANTE: WordPress admin requiere 'unsafe-inline' y 'unsafe-eval' para funcionar
 * correctamente (Gutenberg, plugins, etc). Solo aplicamos CSP restrictivo en producción
 * para el frontend de la API REST.
 */
function starter_security_headers() {
    if (headers_sent()) { return; }
    $is_prod = defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production';
    $is_admin = is_admin();
    $is_login = isset($GLOBALS['pagenow']) && in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'));

    // Headers básicos de seguridad (siempre activos)
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN'); // Permite iframe en portfolio pero protege contra clickjacking externo
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()');

    // CSP diferenciado: Admin/Login vs API REST
    if ($is_admin || $is_login) {
        // WordPress admin necesita permisos relajados para Gutenberg y plugins
        $csp = "default-src 'self'; ";
        $csp .= "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://admin.example.com; ";
        $csp .= "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ";
        $csp .= "img-src 'self' https: data: blob:; ";
        $csp .= "font-src 'self' https://fonts.gstatic.com data:; ";
        $csp .= "connect-src 'self' https://admin.example.com; ";
        $csp .= "frame-ancestors https://portfolio.sergioja.com; ";
        $csp .= "base-uri 'self'; ";
        $csp .= "form-action 'self'; ";
        $csp .= "object-src 'none';";
        
        // Admin CSP ya incluye unsafe-inline/eval necesarios para WP
        header("Content-Security-Policy: $csp");
    } else {
        // API REST: CSP estricto en modo enforcement
        $csp = "default-src 'self'; ";
        $csp .= "script-src 'self'; "; // Sin unsafe-inline ni unsafe-eval
        $csp .= "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ";
        $csp .= "img-src 'self' https://admin.example.com https://example.com data: blob:; ";
        $csp .= "font-src 'self' https://fonts.gstatic.com data:; ";
        $csp .= "connect-src 'self' https://example.com https://admin.example.com; ";
        $csp .= "frame-ancestors https://portfolio.sergioja.com; ";
        $csp .= "base-uri 'self'; ";
        $csp .= "form-action 'self'; ";
        $csp .= "object-src 'none';";
        
        // En API REST, enforcement completo
        header("Content-Security-Policy: $csp");
    }

    // HSTS solo en producción sobre HTTPS
    if ($is_prod && is_ssl()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

add_action('send_headers', 'starter_security_headers', 1);
add_filter('rest_pre_serve_request', function($served, $result, $request) {
    starter_security_headers();
    return $served;
}, 1, 3);

/**
 * Prevenir que SiteGround Dynamic Cache (y cualquier proxy/CDN) cachee respuestas REST API.
 * 
 * Las respuestas REST varían por usuario (JWT + membresía), por lo que el caché de página/proxy
 * NO debe almacenarlas. Esto NO afecta Memcached, Object Cache ni transients de WordPress.
 * 
 * Se usa rest_post_dispatch (prioridad muy alta) para garantizar que estos headers se envíen
 * en TODAS las respuestas REST, incluso si un endpoint específico no los define.
 */
add_filter('rest_post_dispatch', function($response, $server, $request) {
    if ($response instanceof WP_REST_Response) {
        // Prevenir caché de proxy/CDN/SiteGround Dynamic Cache
        // "private" PRIMERO: SiteGround SuperCacher lo usa como señal para excluir del Dynamic Cache
        $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        // Vary por Authorization para que proxies entiendan que la respuesta depende del usuario
        $response->header('Vary', 'Authorization, Accept');
        // Header específico de SiteGround para excluir del Dynamic Cache
        $response->header('X-Cache-Enabled', 'False');
    }
    return $response;
}, 1, 3);
