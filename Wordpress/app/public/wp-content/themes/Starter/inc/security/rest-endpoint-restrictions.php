<?php
if (!defined('ABSPATH')) { exit; }

add_filter('rest_endpoints', function($endpoints) {
    if (!is_user_logged_in()) {
        $patterns = array(
            '#^/wp/v2/users$#',
            '#^/wp/v2/users/(?P<id>\\d+)$#',
            '#^/wp/v2/settings$#',
            '#^/wp/v2/plugins$#',
            '#^/wp/v2/themes$#',
            '#^/wp/v2/application-passwords.*$#',
            '#^/wp/v2/users/(?P<id>\\d+)/application-passwords.*$#',
            '#^/wp/v2/comments$#',
            '#^/wp/v2/comments/(?P<id>\\d+)$#',
            // oEmbed REST endpoints
            '#^/oembed/1\.0/.*$#',
            // Site Health endpoints
            '#^/wp-site-health/v1.*$#',
            '#^/site-health/v1.*$#',
            // Media listing endpoints
            '#^/wp/v2/media$#',
            '#^/wp/v2/media/(?P<id>\\d+)$#',
            // WooCommerce Store API (pública por diseño, la ocultamos para tienda privada)
            '#^/wc/store.*$#',
            // WooCommerce Analytics/Admin endpoints
            '#^/wc-analytics.*$#',
            '#^/wc/admin.*$#'
        );
        foreach (array_keys($endpoints) as $route) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $route)) {
                    unset($endpoints[$route]);
                    break;
                }
            }
        }
    }
    return $endpoints;
}, 10, 1);

/**
 * Bloquear acceso directo a /wc/v3 para usuarios no autenticados.
 * 
 * Se usa rest_pre_dispatch (prioridad alta) en lugar de rest_endpoints porque
 * rest_endpoints se ejecuta ANTES de que el plugin JWT procese el token Bearer,
 * causando que is_user_logged_in() retorne false para usuarios autenticados vía JWT.
 * 
 * rest_pre_dispatch se ejecuta DESPUÉS de la autenticación JWT, permitiendo
 * distinguir correctamente entre usuarios anónimos y autenticados.
 * 
 * El proxy interno (/starter/v1/wc) usa wp_remote_request con credenciales OAuth
 * server-side, por lo que no se ve afectado por esta restricción.
 */
add_filter('rest_pre_dispatch', function($result, $server, $request) {
    // Si ya hay un error o respuesta previa, no sobreescribirlo
    if ($result !== null) {
        return $result;
    }
    
    $route = $request->get_route();
    
    // Solo bloquear rutas /wc/v3
    if (strpos($route, '/wc/v3') !== 0) {
        return $result;
    }
    
    // Permitir si el usuario está autenticado (JWT ya procesado en este punto)
    if (is_user_logged_in()) {
        return $result;
    }
    
    // Permitir si tiene credenciales OAuth (peticiones del proxy interno)
    $params = $request->get_params();
    if (!empty($params['consumer_key']) || !empty($params['oauth_consumer_key'])) {
        return $result;
    }
    
    // Verificar header Authorization Basic (credenciales WC del proxy)
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    if (empty($auth_header) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (!empty($auth_header) && strpos($auth_header, 'Basic ') === 0) {
        return $result;
    }
    
    // Bloquear: usuario anónimo sin credenciales intentando acceder a /wc/v3 directamente
    return new WP_Error(
        'rest_forbidden',
        'Acceso directo a la API de WooCommerce no permitido. Usa el proxy /starter/v1/wc.',
        ['status' => 403]
    );
}, 10, 3);
