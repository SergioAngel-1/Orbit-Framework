<?php
/**
 * WooCommerce Proxy Permissions Handler
 * 
 * Handles authorization and permissions for WooCommerce proxy endpoints
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_WC_Permissions {
    
    /**
     * Check if user has permission to access the endpoint
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function check(WP_REST_Request $request) {
        $route = isset($request['route']) ? (string)$request['route'] : '';
        $method = strtoupper($request->get_method());
        
        // Orders endpoint has special rules
        if (preg_match('#^orders(?:/|$)#', $route)) {
            return self::check_orders_permission($method, $request);
        }
        
        // Reviews endpoint: GET public, POST/PUT require auth
        if (preg_match('#^products/reviews(?:/|$)#', $route)) {
            return self::check_reviews_permission($method);
        }
        
        // All other endpoints are public
        return true;
    }
    
    /**
     * Check permissions for orders endpoint
     * 
     * @param string $method HTTP method
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function check_orders_permission($method, WP_REST_Request $request) {
        switch ($method) {
            case 'POST':
                // Crear órdenes requiere autenticación
                // El frontend (CheckoutPage.tsx) ya lo exige — el backend debe ser consistente
                return is_user_logged_in();
                
            case 'PUT':
            case 'PATCH':
                // Require authentication for updates
                if (!is_user_logged_in()) {
                    return false;
                }
                
                // Validate order ownership: user can only update their own orders
                $route = isset($request['route']) ? (string)$request['route'] : '';
                if (preg_match('#^orders/(\d+)#', $route, $matches)) {
                    $order_id = intval($matches[1]);
                    $order = wc_get_order($order_id);
                    if (!$order) {
                        return false;
                    }
                    $current_user_id = get_current_user_id();
                    if (intval($order->get_customer_id()) !== $current_user_id) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf(
                                '[Starter WC Permissions] PUT /orders/%d rechazado: usuario %d no es dueño (customer_id=%d)',
                                $order_id,
                                $current_user_id,
                                $order->get_customer_id()
                            ));
                        }
                        return false;
                    }
                }
                
                return true;
                
            case 'GET':
                // Require authentication for viewing orders
                if (!is_user_logged_in()) {
                    return false;
                }
                
                // Users can only view their own orders
                $current_user_id = get_current_user_id();
                $params = $request->get_params();
                
                if (isset($params['customer'])) {
                    return intval($params['customer']) === intval($current_user_id);
                }
                
                // Allow if no customer filter (will be filtered by WooCommerce)
                return true;
                
            default:
                return false;
        }
    }
    
    /**
     * Check permissions for reviews endpoint
     * 
     * @param string $method HTTP method
     * @return bool
     */
    private static function check_reviews_permission($method) {
        switch ($method) {
            case 'GET':
                // Reviews are publicly readable
                return true;
                
            case 'POST':
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                // Write operations require authentication
                return is_user_logged_in();
                
            default:
                return false;
        }
    }
    
    /**
     * Check if user is admin
     * 
     * @return bool
     */
    public static function is_admin() {
        return current_user_can('manage_options');
    }
    
    /**
     * Check if user can manage WooCommerce
     * 
     * @return bool
     */
    public static function can_manage_woocommerce() {
        return current_user_can('manage_woocommerce');
    }
}
