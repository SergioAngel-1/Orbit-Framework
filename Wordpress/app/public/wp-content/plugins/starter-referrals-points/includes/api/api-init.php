<?php
/**
 * Inicialización de API REST
 * 
 * Funciones para inicializar y configurar la API REST.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoints de API
 */
function starter_rp_register_api_endpoints() {
    add_action('rest_api_init', 'starter_rp_register_routes');
}

/**
 * Registrar rutas de API
 */
function starter_rp_register_routes() {
    // Namespace base para nuestros endpoints
    $namespace = 'starter/v1';
    
    // === ENDPOINTS DE PUNTOS ===
    
    // Ruta para obtener Virtual Coins del usuario actual
    register_rest_route($namespace, '/points', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_current_user_points_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Ruta para obtener transacciones de Virtual Coins del usuario actual
    register_rest_route($namespace, '/points/transactions', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_user_transactions_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Ruta para transferir Virtual Coins a otro usuario usando código de referido
    register_rest_route($namespace, '/points/transfer', [
        'methods' => 'POST',
        'callback' => 'starter_rp_transfer_points_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Ruta para obtener información de Virtual Coins de un producto (pública)
    register_rest_route($namespace, '/product/(?P<id>\d+)/points', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_product_points_endpoint',
        'permission_callback' => '__return_true'
    ]);
    
    // === ENDPOINTS DE REFERIDOS ===
    
    // Ruta para obtener información de referidos del usuario actual
    register_rest_route($namespace, '/referrals', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_user_referrals_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Ruta para obtener estadísticas de referidos del usuario actual
    register_rest_route($namespace, '/referrals/stats', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_user_referral_stats_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Ruta para obtener el código y enlace de referido del usuario actual
    register_rest_route($namespace, '/referrals/code', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_user_referral_code_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Ruta para obtener la configuración del programa de referidos
    register_rest_route($namespace, '/referrals/config', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_referral_config_endpoint',
        'permission_callback' => '__return_true'
    ]);
    
    // Ruta para validar un código de referido
    register_rest_route($namespace, '/referrals/validate-code', [
        'methods' => 'GET',
        'callback' => 'starter_rp_validate_referral_code_endpoint',
        'permission_callback' => '__return_true' // Permiso público para permitir validación durante registro
    ]);
    
    // Ruta para obtener el referidor del usuario actual (quién me refirió)
    register_rest_route($namespace, '/referrals/my-referrer', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_my_referrer_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // === ENDPOINTS DE ADMINISTRACIÓN ===
    
    // Ruta para obtener resumen de estadísticas (solo admin)
    register_rest_route($namespace, '/admin/stats', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_admin_stats_endpoint',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
    
    // === ENDPOINTS DE CONFIGURACIÓN ===
    
    // Ruta para verificar el estado del sistema (cualquier usuario autenticado)
    // El callback ya valida user_id y devuelve datos personalizados por usuario
    register_rest_route($namespace, '/system/status', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_system_status_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Ruta para obtener configuración pública del sistema
    register_rest_route($namespace, '/system/config', [
        'methods' => 'GET',
        'callback' => 'starter_rp_get_public_system_config_endpoint',
        'permission_callback' => '__return_true' // Público
    ]);
    
    // Ruta para validar configuraciones (solo administradores)
    register_rest_route($namespace, '/system/validate', [
        'methods' => 'GET',
        'callback' => 'starter_rp_validate_system_config_endpoint',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
    
    // === ENDPOINTS DE REFERRAL-API.PHP ===
    
    // Registramos los endpoints definidos en referral-api.php
    starter_rp_register_referrer_endpoint();
}
