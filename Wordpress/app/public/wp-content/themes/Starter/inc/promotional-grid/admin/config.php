<?php
/**
 * Configuración para la página de administración de grillas por membresía
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener niveles de membresía
 */
function fipg_get_membership_levels() {
    if (class_exists('Starter_Memberships')) {
        return Starter_Memberships::get_all_membership_levels();
    }
    
    // Fallback si el plugin de membresías no está activo
    return array(
        0 => array('name' => 'Zanahoria', 'icon' => '🥕', 'color' => '#FF6B35'),
        1 => array('name' => 'Bronce', 'icon' => '🥉', 'color' => '#CD7F32'),
        2 => array('name' => 'Plateada', 'icon' => '🥈', 'color' => '#C0C0C0'),
        3 => array('name' => 'Dorada', 'icon' => '🥇', 'color' => '#FFD700'),
        4 => array('name' => 'Diamante', 'icon' => '💎', 'color' => '#B9F2FF'),
        5 => array('name' => 'Antigüedad', 'icon' => '👑', 'color' => '#8B4513'),
    );
}

/**
 * Orden de visualización (mayor a menor)
 */
function fipg_get_display_order() {
    return array(5, 4, 3, 2, 1, 0);
}

/**
 * Obtener categorías de productos organizadas por nivel de membresía
 */
function fipg_get_categories_by_level() {
    $categories_by_level = array();
    
    // Inicializar arrays para cada nivel
    for ($i = 0; $i <= 5; $i++) {
        $categories_by_level[$i] = array();
    }
    
    $product_categories = get_terms(array(
        'taxonomy'   => 'product_cat',
        'orderby'    => 'name',
        'order'      => 'ASC',
        'hide_empty' => false,
    ));
    
    if (!empty($product_categories) && !is_wp_error($product_categories)) {
        foreach ($product_categories as $cat) {
            $min_level = get_term_meta($cat->term_id, '_min_membership_level', true);
            $min_level = $min_level !== '' ? intval($min_level) : 0;
            
            if (isset($categories_by_level[$min_level])) {
                $categories_by_level[$min_level][] = $cat;
            }
        }
    }
    
    return $categories_by_level;
}

/**
 * Obtener todos los productos de WooCommerce
 */
function fipg_get_all_products() {
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'post_status'    => 'publish',
    );
    
    return get_posts($args);
}

/**
 * Obtener productos filtrados por nivel de membresía
 * Un producto es elegible si todas sus categorías son accesibles para el nivel dado
 */
function fipg_get_products_for_level($level) {
    $all_products = fipg_get_all_products();
    $filtered_products = array();
    
    foreach ($all_products as $product) {
        $product_categories = get_the_terms($product->ID, 'product_cat');
        $product_min_level = 0;
        
        if ($product_categories && !is_wp_error($product_categories)) {
            foreach ($product_categories as $cat) {
                $cat_min_level = intval(get_term_meta($cat->term_id, '_min_membership_level', true));
                if ($cat_min_level > $product_min_level) {
                    $product_min_level = $cat_min_level;
                }
            }
        }
        
        // El producto es elegible si su nivel requerido es <= al nivel de la grilla
        if ($product_min_level <= $level) {
            $filtered_products[] = $product;
        }
    }
    
    return $filtered_products;
}

/**
 * Generar ID único para una grilla
 */
function fipg_generate_grid_id() {
    return 'grid_' . uniqid();
}
