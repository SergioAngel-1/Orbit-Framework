<?php
/**
 * Manejadores AJAX para menús
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar los manejadores AJAX para menús
 */
function starter_register_menu_ajax_handlers() {
    // Manejador AJAX para obtener el menú principal
    add_action('wp_ajax_get_main_menu', 'starter_ajax_get_main_menu');
    add_action('wp_ajax_nopriv_get_main_menu', 'starter_ajax_get_main_menu');
    
    // Manejador AJAX para obtener categorías de productos
    add_action('wp_ajax_get_product_categories', 'starter_ajax_get_product_categories');
    add_action('wp_ajax_nopriv_get_product_categories', 'starter_ajax_get_product_categories');
}
add_action('init', 'starter_register_menu_ajax_handlers');

/**
 * Manejador AJAX para obtener el menú principal
 */
function starter_ajax_get_main_menu() {
    // Verificar nonce para seguridad
    if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'starter_menu_nonce')) {
        wp_send_json_error([
            'message' => 'Error de seguridad: Nonce inválido'
        ]);
    }
    
    // Obtener la ubicación del menú principal
    $locations = get_nav_menu_locations();
    
    if (!isset($locations['main-menu'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Error AJAX: Menú principal no encontrado');
        }
        wp_send_json_error([
            'message' => 'Menú principal no encontrado',
            'debug' => [
                'locations' => $locations
            ]
        ]);
    }
    
    $menu_id = $locations['main-menu'];
    $menu_object = wp_get_nav_menu_object($menu_id);
    
    if (!$menu_object) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Error AJAX: Objeto de menú no encontrado para ID: ' . $menu_id);
        }
        wp_send_json_error([
            'message' => 'Objeto de menú no encontrado',
            'debug' => [
                'menu_id' => $menu_id
            ]
        ]);
    }
    
    // Obtener los elementos del menú
    $menu_items = wp_get_nav_menu_items($menu_id);
    
    if (!$menu_items) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Error AJAX: No se encontraron elementos en el menú: ' . $menu_object->name);
        }
        wp_send_json_error([
            'message' => 'No se encontraron elementos en el menú',
            'debug' => [
                'menu_name' => $menu_object->name,
                'menu_id' => $menu_id
            ]
        ]);
    }
    
    // Procesar los elementos del menú para crear una estructura jerárquica
    $menu_tree = starter_build_menu_tree($menu_items);
    
    wp_send_json_success([
        'menu' => [
            'id' => $menu_id,
            'name' => $menu_object->name,
            'items' => $menu_tree
        ]
    ]);
}

/**
 * Manejador AJAX para obtener categorías de productos
 */
function starter_ajax_get_product_categories() {
    // Verificar nonce para seguridad
    if (!isset($_REQUEST['nonce']) || !wp_verify_nonce($_REQUEST['nonce'], 'starter_menu_nonce')) {
        wp_send_json_error([
            'message' => 'Error de seguridad: Nonce inválido'
        ]);
    }
    
    // Parámetros opcionales
    $parent = isset($_REQUEST['parent']) ? intval($_REQUEST['parent']) : 0;
    $hide_empty = isset($_REQUEST['hide_empty']) ? (bool)$_REQUEST['hide_empty'] : false;
    
    // Obtener categorías de productos
    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => $hide_empty,
        'parent' => $parent,
    ]);
    
    if (is_wp_error($categories)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Error AJAX al obtener categorías de productos: ' . $categories->get_error_message());
        }
        wp_send_json_error([
            'message' => 'Error al obtener categorías de productos',
            'debug' => [
                'error' => $categories->get_error_message()
            ]
        ]);
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
    
    wp_send_json_success([
        'categories' => $processed_categories
    ]);
}