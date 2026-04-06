<?php
/**
 * Funciones de productos de membresía
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener producto de membresía por nivel
 * 
 * @param int $level Nivel de membresía
 * @return WC_Product|null Producto o null
 */
function starter_get_membership_product_by_level($level) {
    $args = [
        'post_type' => 'product',
        'posts_per_page' => 1,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => '_is_membership_product',
                'value' => 'yes',
                'compare' => '='
            ],
            [
                'key' => '_membership_level',
                'value' => $level,
                'compare' => '='
            ]
        ]
    ];
    
    $products = get_posts($args);
    
    if (empty($products)) {
        return null;
    }
    
    return wc_get_product($products[0]->ID);
}

/**
 * Verificar si el carrito contiene productos de membresía
 * 
 * @return bool True si contiene membresías
 */
function starter_cart_has_membership() {
    if (!WC()->cart) {
        return false;
    }
    
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (starter_is_membership_product($cart_item['product_id'])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Obtener el nivel de membresía más alto en el carrito
 * 
 * @return int Nivel más alto o 0
 */
function starter_get_highest_membership_in_cart() {
    if (!WC()->cart) {
        return 0;
    }
    
    $highest = 0;
    
    foreach (WC()->cart->get_cart() as $cart_item) {
        $level = starter_get_product_membership_level($cart_item['product_id']);
        if ($level !== null && $level > $highest) {
            $highest = $level;
        }
    }
    
    return $highest;
}

/**
 * Agregar datos de membresía a la respuesta de producto en API
 */
add_filter('woocommerce_rest_prepare_product_object', 'starter_add_membership_data_to_product_api', 10, 3);

function starter_add_membership_data_to_product_api($response, $product, $request) {
    $data = $response->get_data();
    
    $is_membership = get_post_meta($product->get_id(), '_is_membership_product', true) === 'yes';
    
    $data['is_membership_product'] = $is_membership;
    
    if ($is_membership) {
        $level = intval(get_post_meta($product->get_id(), '_membership_level', true));
        $level_info = Starter_Memberships::get_membership_level($level);
        
        $data['membership_data'] = [
            'level' => $level,
            'level_name' => $level_info['name'],
            'level_slug' => $level_info['slug'],
            'level_icon' => $level_info['icon'],
            'level_color' => $level_info['color'],
            'monthly_points' => intval(get_post_meta($product->get_id(), '_membership_monthly_points', true)),
            'duration_days' => intval(get_post_meta($product->get_id(), '_membership_duration_days', true)) ?: 30,
            'benefits' => array_filter(explode("\n", get_post_meta($product->get_id(), '_membership_benefits', true) ?: ''))
        ];
    }
    
    $response->set_data($data);
    
    return $response;
}
