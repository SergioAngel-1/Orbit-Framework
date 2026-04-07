<?php
/**
 * Funciones para gestionar categorías de productos en menús
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener categorías de productos para menús
 * 
 * @param int $parent ID de la categoría padre (0 para categorías de nivel superior)
 * @param bool $hide_empty Ocultar categorías vacías
 * @return array|WP_Error Array de categorías o error
 */
function starter_get_product_categories($parent = 0, $hide_empty = false) {
    // Verificar que WooCommerce está activo
    if (!function_exists('WC')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Error: WooCommerce no está activo');
        }
        return new WP_Error('woocommerce_inactive', 'WooCommerce no está activo');
    }
    
    // Obtener categorías de productos
    $args = [
        'taxonomy' => 'product_cat',
        'hide_empty' => $hide_empty,
        'parent' => $parent,
    ];
    
    $categories = get_terms($args);
    
    if (is_wp_error($categories)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Error al obtener categorías de productos: ' . $categories->get_error_message());
        }
        return $categories;
    }
    
    // Procesar categorías para incluir metadatos
    $processed_categories = [];
    
    foreach ($categories as $category) {
        $category_data = [
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'count' => $category->count,
            'description' => $category->description,
            'parent' => $category->parent,
            'link' => get_term_link($category)
        ];
        
        // Obtener imagen destacada si existe
        $thumbnail_id = get_term_meta($category->term_id, 'thumbnail_id', true);
        if ($thumbnail_id) {
            $image = wp_get_attachment_image_src($thumbnail_id, 'thumbnail');
            if ($image) {
                $category_data['image'] = $image[0];
                $category_data['image_id'] = $thumbnail_id;
            }
        }
        
        // Verificar si tiene subcategorías
        $has_children = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'parent' => $category->term_id,
            'fields' => 'ids',
            'number' => 1
        ]);
        
        $category_data['has_children'] = !empty($has_children) && !is_wp_error($has_children);
        
        $processed_categories[] = $category_data;
    }
    
    return $processed_categories;
}

/**
 * Obtener categorías de productos con estructura jerárquica
 * 
 * @param bool $hide_empty Ocultar categorías vacías
 * @return array|WP_Error Array de categorías o error
 */
function starter_get_product_categories_tree($hide_empty = false) {
    // Obtener todas las categorías de nivel superior
    $top_level_categories = starter_get_product_categories(0, $hide_empty);
    
    if (is_wp_error($top_level_categories)) {
        return $top_level_categories;
    }
    
    // Construir árbol de categorías
    foreach ($top_level_categories as &$category) {
        if ($category['has_children']) {
            $children = starter_get_product_categories($category['id'], $hide_empty);
            if (!is_wp_error($children)) {
                $category['children'] = $children;
            }
        }
    }
    
    return $top_level_categories;
}

/**
 * Obtener categorías destacadas para mostrar en el menú
 * 
 * @param int $limit Número máximo de categorías a devolver
 * @return array Array de categorías destacadas
 */
function starter_get_featured_product_categories($limit = 5) {
    // Verificar que WooCommerce está activo
    if (!function_exists('WC')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Error: WooCommerce no está activo');
        }
        return [];
    }
    
    // Obtener categorías con productos destacados
    $args = [
        'taxonomy' => 'product_cat',
        'hide_empty' => true,
        'meta_key' => 'featured',
        'meta_value' => 'yes',
        'number' => $limit
    ];
    
    $featured_categories = get_terms($args);
    
    if (is_wp_error($featured_categories) || empty($featured_categories)) {
        // Si no hay categorías destacadas, obtener las categorías con más productos
        $args = [
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'orderby' => 'count',
            'order' => 'DESC',
            'number' => $limit
        ];
        
        $featured_categories = get_terms($args);
        
        if (is_wp_error($featured_categories)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Error al obtener categorías destacadas: ' . $featured_categories->get_error_message());
            }
            return [];
        }
    }
    
    // Procesar categorías para incluir metadatos
    $processed_categories = [];
    
    foreach ($featured_categories as $category) {
        $category_data = [
            'id' => $category->term_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'count' => $category->count,
            'description' => $category->description,
            'link' => get_term_link($category)
        ];
        
        // Obtener imagen destacada si existe
        $thumbnail_id = get_term_meta($category->term_id, 'thumbnail_id', true);
        if ($thumbnail_id) {
            $image = wp_get_attachment_image_src($thumbnail_id, 'medium');
            if ($image) {
                $category_data['image'] = $image[0];
                $category_data['image_id'] = $thumbnail_id;
            }
        }
        
        $processed_categories[] = $category_data;
    }
    
    return $processed_categories;
}