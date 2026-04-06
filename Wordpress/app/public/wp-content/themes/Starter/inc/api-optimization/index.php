<?php
/**
 * API Optimization Module - Punto de entrada
 * 
 * Este archivo sirve como punto de entrada para el módulo de optimización de API,
 * cargando todos los componentes necesarios y registrando los hooks principales.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Registrar la activación del módulo

/**
 * Constante para desactivar temporalmente toda la optimización de API para depuración
 */
if (!defined('DISABLE_API_OPTIMIZATION')) {
    define('DISABLE_API_OPTIMIZATION', false);
}

// Cargar componentes individuales
require_once dirname(__FILE__) . '/cache-compatibility.php';
require_once dirname(__FILE__) . '/batch-processing.php';
require_once dirname(__FILE__) . '/fields-selection.php';
require_once dirname(__FILE__) . '/route-helpers.php';
require_once dirname(__FILE__) . '/optimization-filters.php';

/**
 * Función para determinar si es una solicitud que debe evitar nuestros filtros de optimización
 * Esto incluye verificaciones para solicitudes de autenticación y administrativas
 */
function starter_should_bypass_api_optimization() {
    // Si la optimización está desactivada globalmente, evitar todos los filtros
    if (defined('DISABLE_API_OPTIMIZATION') && DISABLE_API_OPTIMIZATION) {
        return true;
    }
    
    // Si es una solicitud AJAX, evitar caché
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return true;
    }
    
    // Verificar si es una solicitud administrativa
    if (is_admin()) {
        return true;
    }
    
    // Verificar si el usuario está actualizando algo
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
        return true;
    }
    
    // Si no hay WP_REST_Request, no es una solicitud REST
    if (!function_exists('rest_get_url_prefix') || empty($_SERVER['REQUEST_URI'])) {
        return false;
    }
    
    // Verificar si la solicitud contiene el prefijo REST
    $rest_prefix = rest_get_url_prefix();
    if (strpos($_SERVER['REQUEST_URI'], "/{$rest_prefix}/") === false) {
        return false;
    }
    
    // Revisar si la ruta contiene patrones sensibles
    $sensitive_patterns = [
        'jwt-auth',
        '/jwt-auth/v1/',
        'token',
        'login',
        'logout',
        'register',
        'user/me',
        'auth',
        'wc/v3/orders',
        'users/me',
        'wp-admin'
    ];
    
    foreach ($sensitive_patterns as $pattern) {
        if (strpos($_SERVER['REQUEST_URI'], $pattern) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Registra los hooks de optimización de API cuando sea apropiado
 */
function starter_register_api_optimization_hooks() {
    // Si es una solicitud que debería evitar la optimización, no registrar hooks
    if (starter_should_bypass_api_optimization()) {
        return;
    }
    
    // Registrar los hooks de optimización
    add_filter('rest_request_after_callbacks', 'starter_optimize_products_endpoint', 20, 3);
    
    // Registrar otros hooks según sea necesario
    do_action('starter_api_optimization_hooks_registered');
}

// Registramos los hooks en la fase de init para tener acceso a más información
add_action('init', 'starter_register_api_optimization_hooks');
