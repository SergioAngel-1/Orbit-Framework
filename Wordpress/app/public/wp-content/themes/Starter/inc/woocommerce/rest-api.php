<?php
/**
 * WooCommerce REST Proxy Endpoints - Refactored
 * 
 * Main entry point for WooCommerce proxy functionality
 * Uses modular architecture for maintainability and reusability
 * 
 * @package Starter
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load all proxy modules
require_once __DIR__ . '/class-wc-credentials.php';
require_once __DIR__ . '/class-wc-cache-manager.php';
require_once __DIR__ . '/class-wc-idempotency.php';
require_once __DIR__ . '/class-wc-http-client.php';
require_once __DIR__ . '/class-wc-permissions.php';
require_once __DIR__ . '/class-wc-proxy-orchestrator.php';

/**
 * Register cache invalidation hooks
 */
Starter_WC_Cache_Manager::register_invalidation_hooks();

/**
 * Register WooCommerce proxy REST routes
 */
if (!function_exists('starter_register_wc_proxy_routes')) {
    function starter_register_wc_proxy_routes() {
        register_rest_route('starter/v1', '/wc/(?P<route>.+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => function (WP_REST_Request $req) {
                    return Starter_WC_Proxy_Orchestrator::handle_request('GET', $req['route'], $req);
                },
                'permission_callback' => function (WP_REST_Request $req) {
                    return Starter_WC_Permissions::check($req);
                },
                'args'                => [],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => function (WP_REST_Request $req) {
                    return Starter_WC_Proxy_Orchestrator::handle_request('POST', $req['route'], $req);
                },
                'permission_callback' => function (WP_REST_Request $req) {
                    return Starter_WC_Permissions::check($req);
                },
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => function (WP_REST_Request $req) {
                    return Starter_WC_Proxy_Orchestrator::handle_request('PUT', $req['route'], $req);
                },
                'permission_callback' => function (WP_REST_Request $req) {
                    return Starter_WC_Permissions::check($req);
                },
            ],
        ], true);
    }
}

/**
 * Backward compatibility functions
 * These maintain the old function names for any code that might be using them
 */

if (!function_exists('starter_get_wc_credentials')) {
    function starter_get_wc_credentials() {
        return Starter_WC_Credentials::get();
    }
}

if (!function_exists('starter_wc_cache_invalidate')) {
    function starter_wc_cache_invalidate() {
        Starter_WC_Cache_Manager::invalidate_all();
    }
}

if (!function_exists('starter_build_wc_url')) {
    function starter_build_wc_url($route, $params = []) {
        return Starter_WC_HTTP_Client::build_url($route, $params);
    }
}

if (!function_exists('starter_wc_permission_callback')) {
    function starter_wc_permission_callback(WP_REST_Request $request) {
        return Starter_WC_Permissions::check($request);
    }
}
