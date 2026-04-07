<?php
/**
 * Manejo de CORS para los endpoints de restablecimiento de contraseña
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agregar encabezados CORS a las respuestas
 */
function starter_password_reset_cors_headers() {
    // Solo aplicar a las rutas de la API REST
    if (strpos($_SERVER['REQUEST_URI'], '/wp-json/starter/v1/') !== false) {
        
        // Obtener el origen de la solicitud
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
        
        // Reutilizar la lista centralizada de cors-functions.php
        $allowed_origins = function_exists('starter_get_allowed_origins') 
            ? starter_get_allowed_origins() 
            : array();
        
        if (!empty($origin) && in_array($origin, $allowed_origins, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
        }
        
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');
        
        // Manejar solicitudes OPTIONS (preflight)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(200);
            exit();
        }
    }
}
add_action('init', 'starter_password_reset_cors_headers');
