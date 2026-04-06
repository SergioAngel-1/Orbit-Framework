<?php
/**
 * Inicialización de API REST para Starter Memberships
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoints de API
 */
function starter_memberships_register_api_endpoints() {
    add_action('rest_api_init', 'starter_memberships_register_routes');
}

/**
 * Registrar rutas de API
 */
function starter_memberships_register_routes() {
    $namespace = 'starter/v1';
    
    // === ENDPOINTS DE MEMBRESÍA DEL USUARIO ===
    
    // Obtener membresía del usuario actual
    register_rest_route($namespace, '/membership', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_user_membership',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Obtener historial de membresías del usuario
    register_rest_route($namespace, '/membership/history', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_membership_history',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Obtener estadísticas de membresía del usuario
    register_rest_route($namespace, '/membership/stats', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_membership_stats',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Obtener beneficios del usuario actual
    register_rest_route($namespace, '/membership/benefits', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_user_benefits',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Obtener beneficios de un nivel específico (público)
    register_rest_route($namespace, '/memberships/levels/(?P<level>\d+)/benefits', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_level_benefits',
        'permission_callback' => '__return_true',
        'args' => [
            'level' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param >= 0 && $param <= 5;
                }
            ]
        ]
    ]);
    
    // === ENDPOINTS DE PRODUCTOS DE MEMBRESÍA ===
    
    // Obtener todos los productos de membresía disponibles
    register_rest_route($namespace, '/memberships/products', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_membership_products',
        'permission_callback' => '__return_true' // Público
    ]);
    
    // Obtener niveles de membresía
    register_rest_route($namespace, '/memberships/levels', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_membership_levels',
        'permission_callback' => '__return_true' // Público
    ]);
    
    // === ENDPOINTS DE CATEGORÍAS ===
    
    // Verificar acceso a una categoría
    register_rest_route($namespace, '/membership/category-access/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'starter_api_check_category_access',
        'permission_callback' => '__return_true',
        'args' => [
            'id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ]
        ]
    ]);
    
    // Obtener categorías accesibles para el usuario
    register_rest_route($namespace, '/membership/accessible-categories', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_accessible_categories',
        'permission_callback' => '__return_true'
    ]);
    
    // === VERIFICACIÓN DE MEMBRESÍA (para QR del carné digital) ===
    
    // Público: verificar membresía por token opaco (no expone user ID)
    register_rest_route($namespace, '/membership/verify/(?P<token>[a-f0-9]{32})', [
        'methods' => 'GET',
        'callback' => 'starter_api_verify_membership',
        'permission_callback' => '__return_true',
        'args' => [
            'token' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return preg_match('/^[a-f0-9]{32}$/', $param);
                }
            ]
        ]
    ]);
    
    // Autenticado: obtener token de verificación del usuario actual
    register_rest_route($namespace, '/membership/verify-token', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_verify_token',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // === ENDPOINTS DE ADMINISTRACIÓN ===
    
    // Obtener estadísticas generales (solo admin)
    register_rest_route($namespace, '/memberships/admin/stats', [
        'methods' => 'GET',
        'callback' => 'starter_api_get_admin_membership_stats',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
    
    // Asignar membresía a usuario (solo admin)
    register_rest_route($namespace, '/memberships/admin/assign', [
        'methods' => 'POST',
        'callback' => 'starter_api_admin_assign_membership',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
}
