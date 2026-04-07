<?php
/**
 * API REST para el sistema de beneficios via handlers
 * 
 * Expone endpoints para consultar beneficios activos del usuario
 * utilizando el sistema de handlers.
 * 
 * @package Starter_Memberships
 * @subpackage Benefits/API
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Benefits_API {
    
    /**
     * Namespace de la API
     */
    const NAMESPACE = 'starter/v1';
    
    /**
     * Registrar endpoints
     */
    public static function register_routes() {
        // Obtener beneficios activos del usuario actual (via handlers)
        register_rest_route(self::NAMESPACE, '/membership/benefits/active', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_active_benefits'],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ]);
        
        // Obtener estado de un beneficio específico
        register_rest_route(self::NAMESPACE, '/membership/benefits/(?P<benefit_key>[a-z_]+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_benefit_status'],
            'permission_callback' => function() {
                return is_user_logged_in();
            },
            'args' => [
                'benefit_key' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return preg_match('/^[a-z_]+$/', $param);
                    }
                ]
            ]
        ]);
        
        // Aplicar un beneficio (para beneficios que requieren acción)
        register_rest_route(self::NAMESPACE, '/membership/benefits/(?P<benefit_key>[a-z_]+)/apply', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'apply_benefit'],
            'permission_callback' => function() {
                return is_user_logged_in();
            },
            'args' => [
                'benefit_key' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return preg_match('/^[a-z_]+$/', $param);
                    }
                ]
            ]
        ]);
        
        // Obtener estadísticas del registro de handlers (solo admin)
        register_rest_route(self::NAMESPACE, '/membership/benefits/registry/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_registry_stats'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    }
    
    /**
     * Obtener beneficios activos del usuario actual
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_active_benefits(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'Debes iniciar sesión', ['status' => 401]);
        }
        
        $registry = starter_benefit_registry();
        $active_benefits = $registry->get_active_benefits_for_user($user_id);
        
        // Formatear para la respuesta
        $formatted = [];
        foreach ($active_benefits as $key => $benefit) {
            $item = [
                'key' => $key,
                'name' => $benefit['name'],
                'description' => $benefit['description'],
                'display_value' => $benefit['display_value'],
                'icon' => self::get_benefit_icon($key)
            ];
            
            // Para category_discount, agregar nombres de categorías
            if ($key === 'category_discount') {
                $item['categories'] = self::get_category_discount_categories($user_id);
            }
            
            $formatted[] = $item;
        }
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'user_id' => $user_id,
                'level' => function_exists('starter_get_user_membership_level') 
                    ? starter_get_user_membership_level($user_id) 
                    : 0,
                'benefits' => $formatted,
                'total_active' => count($formatted)
            ]
        ], 200);
    }
    
    /**
     * Obtener estado de un beneficio específico
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_benefit_status(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $benefit_key = $request->get_param('benefit_key');
        
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'Debes iniciar sesión', ['status' => 401]);
        }
        
        $registry = starter_benefit_registry();
        $handler = $registry->get($benefit_key);
        
        if (!$handler) {
            return new WP_Error('benefit_not_found', 'Beneficio no encontrado', ['status' => 404]);
        }
        
        $is_enabled = $handler->is_enabled_for_user($user_id);
        $config = $handler->get_config_for_user($user_id);
        
        $response_data = [
            'key' => $benefit_key,
            'name' => $handler->get_name(),
            'description' => $handler->get_description(),
            'is_enabled' => $is_enabled,
            'display_value' => $is_enabled ? $handler->get_display_value($user_id) : null,
            'config' => $is_enabled ? $config : null,
            'icon' => self::get_benefit_icon($benefit_key)
        ];
        
        // Para category_discount, agregar nombres de categorías
        if ($benefit_key === 'category_discount' && $is_enabled && $config) {
            $response_data['category_names'] = self::get_category_names_from_config($config);
        }
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $response_data
        ], 200);
    }
    
    /**
     * Obtener nombres de categorías desde la configuración
     * 
     * @param array $config
     * @return array
     */
    private static function get_category_names_from_config($config) {
        $category_names = [];
        $category_ids = $config['categories'] ?? [];
        
        if (empty($category_ids)) {
            return ['Todas las categorías'];
        }
        
        foreach ($category_ids as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $category_names[] = $term->name;
            }
        }
        
        return $category_names;
    }
    
    /**
     * Aplicar un beneficio
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function apply_benefit(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $benefit_key = $request->get_param('benefit_key');
        $context = $request->get_json_params();
        
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'Debes iniciar sesión', ['status' => 401]);
        }
        
        $registry = starter_benefit_registry();
        $handler = $registry->get($benefit_key);
        
        if (!$handler) {
            return new WP_Error('benefit_not_found', 'Beneficio no encontrado', ['status' => 404]);
        }
        
        if (!$handler->is_enabled_for_user($user_id)) {
            return new WP_Error('benefit_not_enabled', 'Este beneficio no está disponible para tu nivel de membresía', ['status' => 403]);
        }
        
        $result = $handler->apply($user_id, $context);
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'key' => $benefit_key,
                'result' => $result
            ]
        ], 200);
    }
    
    /**
     * Obtener estadísticas del registro (solo admin)
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_registry_stats(WP_REST_Request $request) {
        $registry = starter_benefit_registry();
        $stats = $registry->get_stats();
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $stats
        ], 200);
    }
    
    /**
     * Obtener nombres de categorías para el beneficio category_discount
     * 
     * @param int $user_id
     * @return array
     */
    private static function get_category_discount_categories(int $user_id): array {
        $registry = starter_benefit_registry();
        $handler = $registry->get('category_discount');
        
        if (!$handler) {
            return ['Todas las categorías'];
        }
        
        $config = $handler->get_config_for_user($user_id);
        
        if (!$config || empty($config['categories'])) {
            return ['Todas las categorías'];
        }
        
        $category_names = [];
        foreach ($config['categories'] as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $category_names[] = $term->name;
            }
        }
        
        return !empty($category_names) ? $category_names : ['Todas las categorías'];
    }
    
    /**
     * Obtener icono de un beneficio
     * 
     * @param string $key
     * @return string
     */
    private static function get_benefit_icon(string $key): string {
        $icons = [
            'category_discount' => '🏷️',
            'referral_bonus' => '👥',
            'referral_membership_bonus' => '🎁',
            'partner_discount_licorera' => '🍺',
            'delivery_options' => '🚚',
            'free_deliveries' => '📦',
            'partner_club_casa_kush' => '🏠',
            'free_samples' => '🌿',
            'security_benefits' => '🛡️',
            'events_discount' => '🎉',
            'exclusive_products' => '⭐',
            'exclusive_content' => '📚',
            'early_access' => '🚀',
            'priority_support' => '💬',
            'monthly_points' => '🌸',
            'extended_period' => '⏱️'
        ];
        
        return $icons[$key] ?? '✨';
    }
}

// Registrar endpoints en rest_api_init
add_action('rest_api_init', [Starter_Benefits_API::class, 'register_routes']);
