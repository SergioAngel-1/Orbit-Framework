<?php
/**
 * WooCommerce Credentials Manager
 * 
 * Handles retrieval and validation of WooCommerce REST API credentials
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_WC_Credentials {
    
    /**
     * Get WooCommerce REST API credentials
     * 
     * @return array|WP_Error Array with [key, secret] or WP_Error on failure
     */
    public static function get() {
        $key = defined('WC_REST_CONSUMER_KEY') 
            ? WC_REST_CONSUMER_KEY 
            : getenv('WC_REST_CONSUMER_KEY');
            
        $secret = defined('WC_REST_CONSUMER_SECRET') 
            ? WC_REST_CONSUMER_SECRET 
            : getenv('WC_REST_CONSUMER_SECRET');
        
        if (empty($key) || empty($secret)) {
            return new WP_Error(
                'wc_credentials_missing',
                'WooCommerce REST credentials are not configured',
                ['status' => 500]
            );
        }
        
        return [$key, $secret];
    }
    
    /**
     * Check if credentials are configured
     * 
     * @return bool
     */
    public static function are_configured() {
        $creds = self::get();
        return !is_wp_error($creds);
    }
    
    /**
     * Get Basic Auth header value
     * 
     * @return string|WP_Error Base64 encoded credentials or error
     */
    public static function get_basic_auth_header() {
        $creds = self::get();
        if (is_wp_error($creds)) {
            return $creds;
        }
        
        list($key, $secret) = $creds;
        return 'Basic ' . base64_encode($key . ':' . $secret);
    }
}
