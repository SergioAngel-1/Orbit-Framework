<?php
/**
 * Banners - REST API
 * 
 * Este archivo contiene la implementación de los endpoints REST API
 * para acceder a los banners desde el frontend.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoints REST API para banners
 */
function starter_register_banner_rest_route() {
    // Endpoint para obtener todos los banners
    register_rest_route('starter/v1', '/banners', array(
        'methods' => 'GET',
        'callback' => 'starter_get_banners',
        'permission_callback' => '__return_true',
    ));
    
    // Endpoint para obtener banners por tipo
    register_rest_route('starter/v1', '/banners/(?P<type>[a-zA-Z0-9_-]+)', array(
        'methods' => 'GET',
        'callback' => 'starter_get_banners_by_type',
        'permission_callback' => '__return_true',
        'args' => array(
            'type' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_string($param);
                }
            ),
        ),
    ));
}
add_action('rest_api_init', 'starter_register_banner_rest_route', 10);

/**
 * Callback para el endpoint de banners
 * 
 * IMPORTANTE: Este endpoint es sensible a membresía - retorna datos diferentes
 * según el nivel del usuario. Se envían headers para prevenir caché del navegador.
 */
function starter_get_banners($request = null) {
    // Detect language
    $lang = 'es';
    if ($request instanceof WP_REST_Request) {
        $lang = function_exists('starter_get_request_lang')
            ? starter_get_request_lang($request->get_param('lang'))
            : ($request->get_param('lang') ?: 'es');
    } elseif (function_exists('starter_get_request_lang')) {
        $lang = starter_get_request_lang();
    }
    
    $args = array(
        'post_type' => 'banner',
        'posts_per_page' => -1,
        'orderby' => 'meta_value_num',
        'meta_key' => '_banner_order',
        'order' => 'ASC',
        'post_status' => 'publish',
    );
    
    $banners = get_posts($args);
    
    // Obtener nivel de membresía del usuario actual
    $user_membership_level = starter_get_current_user_membership_level();
    
    if (empty($banners)) {
        $response = new WP_REST_Response(array(), 200);
        // Headers para prevenir caché - endpoint sensible a membresía
        $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        $response->header('X-Membership-Level', $user_membership_level);
        return $response;
    }
    
    $data = array();
    
    foreach ($banners as $banner) {
        // Procesar banner con filtrado por membresía y lang
        $banner_data = starter_process_banner_for_api($banner, $user_membership_level, $lang);
        
        // Si el usuario no tiene acceso, el helper retorna null
        if ($banner_data !== null) {
            $data[] = $banner_data;
        }
    }
    
    $response = new WP_REST_Response($data, 200);
    // Headers para prevenir caché - endpoint sensible a membresía
    $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
    $response->header('Pragma', 'no-cache');
    $response->header('Expires', '0');
    $response->header('X-Membership-Level', $user_membership_level);
    return $response;
}

/**
 * Callback para el endpoint de banners por tipo
 * 
 * IMPORTANTE: Este endpoint es sensible a membresía - retorna datos diferentes
 * según el nivel del usuario. Se envían headers para prevenir caché del navegador.
 */
function starter_get_banners_by_type($request) {
    $type = $request->get_param('type');
    
    if (empty($type)) {
        return new WP_Error('invalid_type', 'Tipo de banner no válido', array('status' => 400));
    }
    
    // Detect language
    $lang = function_exists('starter_get_request_lang')
        ? starter_get_request_lang($request->get_param('lang'))
        : ($request->get_param('lang') ?: 'es');
    
    // Obtener nivel de membresía del usuario actual
    $user_membership_level = starter_get_current_user_membership_level();
    
    $args = array(
        'post_type' => 'banner',
        'posts_per_page' => -1,
        'orderby' => 'meta_value_num',
        'meta_key' => '_banner_order',
        'order' => 'ASC',
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => '_banner_type',
                'value' => $type,
                'compare' => '=',
            ),
        ),
    );
    
    $banners = get_posts($args);
    
    if (empty($banners)) {
        $response = new WP_REST_Response(array(), 200);
        $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        $response->header('X-Membership-Level', $user_membership_level);
        return $response;
    }
    
    $data = array();
    
    foreach ($banners as $banner) {
        // Procesar banner con filtrado por membresía y lang
        $banner_data = starter_process_banner_for_api($banner, $user_membership_level, $lang);
        
        // Si el usuario no tiene acceso, el helper retorna null
        if ($banner_data !== null) {
            $data[] = $banner_data;
        }
    }
    
    $response = new WP_REST_Response($data, 200);
    // Headers para prevenir caché - endpoint sensible a membresía
    $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
    $response->header('Pragma', 'no-cache');
    $response->header('Expires', '0');
    $response->header('X-Membership-Level', $user_membership_level);
    return $response;
}
