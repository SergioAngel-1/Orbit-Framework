<?php
/**
 * WooCommerce Idempotency Handler
 * 
 * Handles idempotent order creation to prevent duplicate orders
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('STARTER_IDEMP_ORDERS_TTL')) {
    define('STARTER_IDEMP_ORDERS_TTL', 86400); // 24 hours
}

class Starter_WC_Idempotency {
    
    const TRANSIENT_PREFIX = 'fi_wc_idemp_orders_';
    const LOCK_PREFIX = 'fi_wc_idemp_orders_lock_';
    const LOCK_TIMEOUT = 30; // seconds
    const WAIT_TIMEOUT = 5.0; // seconds
    const POLL_INTERVAL = 200000; // microseconds (200ms)
    
    /**
     * Check if route requires idempotency handling
     * 
     * @param string $method HTTP method
     * @param string $route API route
     * @return bool
     */
    public static function is_idempotent_route($method, $route) {
        return (strtoupper($method) === 'POST' && preg_match('#^orders(?:/|$)#', (string)$route));
    }
    
    /**
     * Extract idempotency key from request
     * 
     * @param WP_REST_Request $request
     * @return string|null
     */
    public static function get_key_from_request(WP_REST_Request $request) {
        $key = $request->get_header('x-idempotency-key');
        if (empty($key)) {
            $key = $request->get_header('idempotency-key');
        }
        
        if (empty($key)) {
            return null;
        }
        
        return sanitize_text_field($key);
    }
    
    /**
     * Validate idempotency key format
     * 
     * @param string $key
     * @return bool|WP_REST_Response True if valid, error response if invalid
     */
    public static function validate_key($key) {
        if (!preg_match('/^[A-Za-z0-9._-]{8,128}$/', $key)) {
            $response = new WP_REST_Response([
                'code' => 'invalid_idempotency_key',
                'message' => 'Idempotency-Key inválida'
            ], 400);
            $response->header('X-Idempotency-Key', $key);
            return $response;
        }
        
        return true;
    }
    
    /**
     * Generate body hash for payload comparison
     * 
     * @param WP_REST_Request $request
     * @return string
     */
    public static function generate_body_hash(WP_REST_Request $request) {
        $body_params = $request->get_json_params();
        if (empty($body_params)) {
            $body_params = $request->get_body_params();
        }
        
        if (!empty($body_params)) {
            return md5(wp_json_encode($body_params));
        }
        
        return '';
    }
    
    /**
     * Get stored idempotent response
     * 
     * @param string $key Idempotency key
     * @return array|false
     */
    public static function get_stored_response($key) {
        $transient_key = self::TRANSIENT_PREFIX . md5($key);
        $stored = get_transient($transient_key);
        
        if ($stored && is_array($stored) && isset($stored['data'], $stored['status'])) {
            return $stored;
        }
        
        return false;
    }
    
    /**
     * Check if stored response matches current request body
     * 
     * @param array $stored Stored response
     * @param string $body_hash Current request body hash
     * @return bool|WP_REST_Response True if matches, conflict response if different
     */
    public static function validate_body_match($stored, $body_hash, $idemp_key) {
        if (!empty($stored['body_hash']) && $body_hash !== '' && $stored['body_hash'] !== $body_hash) {
            $response = new WP_REST_Response([
                'code' => 'idempotency_key_conflict',
                'message' => 'Idempotency-Key ya utilizada con un contenido diferente'
            ], 409);
            $response->header('X-Idempotency-Key', $idemp_key);
            return $response;
        }
        
        return true;
    }
    
    /**
     * Create response from stored idempotent data
     * 
     * @param array $stored
     * @param string $idemp_key
     * @return WP_REST_Response
     */
    public static function create_replayed_response($stored, $idemp_key) {
        $response = new WP_REST_Response($stored['data'], intval($stored['status']));
        
        if (!empty($stored['headers']) && is_array($stored['headers'])) {
            foreach ($stored['headers'] as $key => $value) {
                $response->header($key, $value);
            }
        }
        
        $response->header('X-Idempotency-Key', $idemp_key);
        $response->header('X-Idempotency-Replayed', 'true');
        
        return $response;
    }
    
    /**
     * Try to acquire lock for idempotency key
     * 
     * @param string $key Idempotency key
     * @return bool True if lock acquired
     */
    public static function acquire_lock($key) {
        $lock_key = self::LOCK_PREFIX . md5($key);
        
        if (!get_transient($lock_key)) {
            set_transient($lock_key, time(), self::LOCK_TIMEOUT);
            return true;
        }
        
        return false;
    }
    
    /**
     * Wait for another process to complete idempotent request
     * 
     * @param string $idemp_key Idempotency key
     * @return WP_REST_Response|false Response if found, false if timeout
     */
    public static function wait_for_completion($idemp_key) {
        $transient_key = self::TRANSIENT_PREFIX . md5($idemp_key);
        $deadline = microtime(true) + self::WAIT_TIMEOUT;
        
        while (microtime(true) < $deadline) {
            $stored = get_transient($transient_key);
            if ($stored && is_array($stored) && isset($stored['data'], $stored['status'])) {
                return self::create_replayed_response($stored, $idemp_key);
            }
            usleep(self::POLL_INTERVAL);
        }
        
        // Still processing
        $response = new WP_REST_Response([
            'code' => 'idempotency_processing',
            'message' => 'La solicitud está siendo procesada'
        ], 409);
        $response->header('X-Idempotency-Key', $idemp_key);
        $response->header('Retry-After', 1);
        
        return $response;
    }
    
    /**
     * Release lock for idempotency key
     * 
     * @param string $key Idempotency key
     */
    public static function release_lock($key) {
        $lock_key = self::LOCK_PREFIX . md5($key);
        delete_transient($lock_key);
    }
    
    /**
     * Store successful idempotent response
     * 
     * @param string $idemp_key Idempotency key
     * @param mixed $data Response data
     * @param int $status HTTP status
     * @param array $headers Response headers
     * @param string $body_hash Request body hash
     */
    public static function store_response($idemp_key, $data, $status, $headers, $body_hash) {
        $transient_key = self::TRANSIENT_PREFIX . md5($idemp_key);
        
        $payload = [
            'data'      => $data,
            'status'    => $status,
            'headers'   => $headers,
            'body_hash' => $body_hash,
            'stored_at' => time(),
        ];
        
        set_transient($transient_key, $payload, STARTER_IDEMP_ORDERS_TTL);
    }
}
