<?php
/**
 * API REST para Home Sections
 * 
 * Este archivo registra las rutas y delega a los handlers especializados.
 * Los módulos auxiliares están en archivos separados para mejor mantenibilidad.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar módulos auxiliares
require_once __DIR__ . '/class-cache-manager.php';
require_once __DIR__ . '/class-auth-helper.php';
require_once __DIR__ . '/class-category-loader.php';
require_once __DIR__ . '/class-sections-handler.php';
require_once __DIR__ . '/class-products-handler.php';
require_once __DIR__ . '/class-admin-endpoints.php';

class Starter_Home_Sections_REST_API {
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        // Inicializar cache manager (registra hooks de invalidación)
        FIHS_Cache_Manager::get_instance();
    }
    
    /**
     * Registrar rutas de API REST
     */
    public function register_routes() {
        $namespace = 'starter/v1';
        
        // GET: Obtener todas las secciones
        register_rest_route($namespace, '/home-sections', array(
            'methods' => 'GET',
            'callback' => array('FIHS_Sections_Handler', 'get_sections'),
            'permission_callback' => '__return_true',
            'args' => array(
                'lang' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => 'es',
                ),
                'include_products' => array(
                    'sanitize_callback' => 'absint',
                    'default' => 0,
                ),
            ),
        ));
        
        // GET: Obtener productos de una sección
        register_rest_route($namespace, '/home-sections/(?P<section_id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array('FIHS_Products_Handler', 'get_section_products'),
            'permission_callback' => '__return_true',
            'args' => array(
                'section_id' => array(
                    'validate_callback' => function($param) {
                        return is_string($param) && preg_match('/^[a-zA-Z0-9_-]+$/', $param);
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
        
        // POST: Crear sección (admin)
        register_rest_route($namespace, '/home-sections', array(
            'methods' => 'POST',
            'callback' => array('FIHS_Admin_Endpoints', 'create_section'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
        
        // PUT: Actualizar sección (admin)
        register_rest_route($namespace, '/home-sections/(?P<section_id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'PUT',
            'callback' => array('FIHS_Admin_Endpoints', 'update_section'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
        
        // DELETE: Eliminar sección (admin)
        register_rest_route($namespace, '/home-sections/(?P<section_id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'DELETE',
            'callback' => array('FIHS_Admin_Endpoints', 'delete_section'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
        
        // GET: Test endpoint
        register_rest_route($namespace, '/test', array(
            'methods' => 'GET',
            'callback' => function() {
                return new WP_REST_Response(array(
                    'status' => 'ok',
                    'message' => 'API de Starter Home Sections funcionando'
                ), 200);
            },
            'permission_callback' => '__return_true',
        ));
        
        // GET: Debug endpoint (admin only)
        register_rest_route($namespace, '/home-sections/debug', array(
            'methods' => 'GET',
            'callback' => array('FIHS_Admin_Endpoints', 'debug_sections'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
        
        // POST: Clear cache (admin)
        register_rest_route($namespace, '/home-sections/clear-cache', array(
            'methods' => 'POST',
            'callback' => array('FIHS_Admin_Endpoints', 'clear_cache'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
    }
}
