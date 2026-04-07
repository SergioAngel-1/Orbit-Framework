<?php
/**
 * Funciones relacionadas con WooCommerce
 * 
 * Este archivo contiene funciones personalizadas para extender WooCommerce
 */

// Verificar si WooCommerce está activo
if (!function_exists('is_woocommerce_activated')) {
    function is_woocommerce_activated() {
        return class_exists('WooCommerce');
    }
}

// Añadir soporte para WooCommerce en el tema
function starter_woocommerce_support() {
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
}
add_action('after_setup_theme', 'starter_woocommerce_support');

// Personalizar los endpoints de la API de WooCommerce
function starter_customize_woocommerce_rest_api() {
    // Permitir acceso público a los endpoints de productos y categorías
    add_filter('woocommerce_rest_check_permissions', 'starter_allow_public_access_to_products', 10, 4);
}
add_action('init', 'starter_customize_woocommerce_rest_api');

// Permitir acceso público a los endpoints de productos y categorías
function starter_allow_public_access_to_products($permission, $context, $object_id, $post_type) {
    // Permitir acceso a productos y categorías de productos
    if ($post_type === 'product' || $post_type === 'product_cat') {
        return true;
    }
    
    // Permitir acceso a variaciones de productos (product_variation)
    // Esto permite que usuarios autenticados vía OAuth puedan ver las variaciones
    if ($post_type === 'product_variation' && $context === 'read') {
        return true;
    }
    
    // Permitir creación de órdenes vía API REST (para checkout)
    // Esto permite que usuarios autenticados vía OAuth puedan crear pedidos
    if ($post_type === 'shop_order' && $context === 'create') {
        return true;
    }
    
    // Permitir lectura de órdenes vía API REST (para ver historial de pedidos)
    // Esto permite que usuarios autenticados vía OAuth puedan ver sus propios pedidos
    // WooCommerce internamente valida que solo vean sus propios pedidos mediante el parámetro 'customer'
    if ($post_type === 'shop_order' && $context === 'read') {
        return true;
    }
    
    return $permission;
}

// Personalizar la respuesta de la API de WooCommerce para productos
function starter_customize_product_response($response, $post, $request) {
    if ($post->post_type !== 'product') {
        return $response;
    }
    
    // Personalizar la respuesta aquí si es necesario
    
    return $response;
}
add_filter('woocommerce_rest_prepare_product', 'starter_customize_product_response', 10, 3);

/**
 * Asegurarse de que las categorías de productos de WooCommerce estén disponibles en los menús
 * 
 * IMPORTANTE: WooCommerce registra product_cat con show_in_nav_menus = true por defecto,
 * pero a veces no aparece en el panel de menús. Esta función fuerza la visibilidad.
 */
function starter_ensure_product_categories_in_menus() {
    // Verificar si WooCommerce está activo
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // Registrar la taxonomía product_cat para que aparezca en los menús
    register_taxonomy_for_object_type('product_cat', 'product');
    
    // Forzar show_in_nav_menus directamente en el objeto de taxonomía global
    global $wp_taxonomies;
    if (isset($wp_taxonomies['product_cat'])) {
        $wp_taxonomies['product_cat']->show_in_nav_menus = true;
        $wp_taxonomies['product_cat']->labels->name = __('Categorías de Productos', 'starter');
        $wp_taxonomies['product_cat']->labels->menu_name = __('Categorías de Productos', 'starter');
    }
}
// Ejecutar DESPUÉS de que WooCommerce registre sus taxonomías (prioridad 99)
add_action('init', 'starter_ensure_product_categories_in_menus', 99);

/**
 * Registrar metabox de categorías de productos en la pantalla de menús
 * Esto asegura que aparezca el panel "Categorías de Productos" en Apariencia > Menús
 */
function starter_add_product_cat_metabox_to_menus() {
    // Solo en la pantalla de menús
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'nav-menus') {
        return;
    }
    
    // Verificar que WooCommerce esté activo
    if (!class_exists('WooCommerce')) {
        return;
    }
    
    // Forzar que product_cat aparezca en nav menus
    global $wp_taxonomies;
    if (isset($wp_taxonomies['product_cat'])) {
        $wp_taxonomies['product_cat']->show_in_nav_menus = true;
    }
}
add_action('admin_head', 'starter_add_product_cat_metabox_to_menus');

/**
 * Registrar explícitamente el metabox de categorías de productos en el admin de menús
 * Esto es necesario porque a veces WordPress no lo registra automáticamente
 */
function starter_register_product_cat_nav_menu_metabox() {
    // Verificar que WooCommerce esté activo
    if (!class_exists('WooCommerce') || !taxonomy_exists('product_cat')) {
        return;
    }
    
    $tax = get_taxonomy('product_cat');
    
    // Registrar el metabox manualmente si no existe
    add_meta_box(
        'add-product_cat',
        $tax->labels->name ?? __('Categorías de Productos', 'starter'),
        'wp_nav_menu_item_taxonomy_meta_box',
        'nav-menus',
        'side',
        'default',
        $tax
    );
}
add_action('admin_head-nav-menus.php', 'starter_register_product_cat_nav_menu_metabox');

/**
 * Añadir información de depuración sobre taxonomías en la pantalla de menús
 */
function starter_debug_taxonomies_in_menu_screen() {
    // Solo mostrar en la pantalla de menús
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'nav-menus') {
        return;
    }
    
    // Verificar si WooCommerce está activo
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error"><p><strong>Error:</strong> WooCommerce no está activado. Las categorías de productos no estarán disponibles en los menús.</p></div>';
        return;
    }
    
    // Obtener todas las taxonomías registradas
    $taxonomies = get_taxonomies(array(), 'objects');
    
    // Verificar si product_cat está registrada
    if (!isset($taxonomies['product_cat'])) {
        echo '<div class="notice notice-error"><p><strong>Error:</strong> La taxonomía product_cat no está registrada. Esto puede indicar un problema con la instalación de WooCommerce.</p></div>';
        return;
    }
    
    // Verificar si product_cat está configurada para aparecer en los menús
    $product_cat = $taxonomies['product_cat'];
    if (!$product_cat->show_in_nav_menus) {
        echo '<div class="notice notice-warning"><p><strong>Advertencia:</strong> La taxonomía product_cat no está configurada para aparecer en los menús (show_in_nav_menus = false).</p></div>';
    } else {
        // Contar categorías de productos
        $categories_count = wp_count_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ));
        
        if (is_wp_error($categories_count) || $categories_count == 0) {
            echo '<div class="notice notice-warning"><p><strong>Advertencia:</strong> No hay categorías de productos disponibles. Crea algunas categorías en WooCommerce > Productos > Categorías.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p><strong>Información:</strong> Hay ' . $categories_count . ' categorías de productos disponibles para usar en los menús. Busca "Categorías de Productos" en la columna izquierda.</p></div>';
        }
    }
}
add_action('admin_notices', 'starter_debug_taxonomies_in_menu_screen');

/**
 * Registrar endpoint de API REST para obtener categorías de productos
 */
function starter_register_product_categories_rest_route() {
    register_rest_route('starter/v1', '/product-categories', array(
        'methods' => 'GET',
        'callback' => 'starter_get_product_categories_callback',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'starter_register_product_categories_rest_route');

/**
 * Callback para el endpoint de categorías de productos
 */
function starter_get_product_categories_callback($request) {
    // Verificar si WooCommerce está activo
    if (!class_exists('WooCommerce')) {
        return new WP_Error('woocommerce_inactive', 'WooCommerce no está activo', array('status' => 500));
    }
    
    // Obtener nivel de membresía del usuario para filtrar categorías exclusivas
    $user_level = function_exists('starter_get_jwt_user_membership_level') 
        ? starter_get_jwt_user_membership_level() 
        : 0;
    
    // Parámetros de la solicitud
    $parent = isset($request['parent']) ? intval($request['parent']) : 0;
    $hide_empty = isset($request['hide_empty']) ? filter_var($request['hide_empty'], FILTER_VALIDATE_BOOLEAN) : false;
    $slug = isset($request['slug']) ? sanitize_text_field($request['slug']) : '';
    
    // Si se proporciona un slug, buscar por slug
    if (!empty($slug)) {
        // Intentar encontrar la categoría por slug exacto primero
        $category = get_term_by('slug', $slug, 'product_cat');
        
        // Si no se encuentra, intentar buscar con slugs normalizados
        if (!$category) {
            // Normalizar el slug de búsqueda (eliminar acentos, convertir a minúsculas, etc.)
            $normalized_search_slug = starter_normalize_slug($slug);
            
            // Obtener todas las categorías
            $all_categories = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'exclude' => get_option('default_product_cat', 0)
            ));
            
            if (!is_wp_error($all_categories)) {
                foreach ($all_categories as $cat) {
                    $normalized_cat_slug = starter_normalize_slug($cat->slug);
                    if ($normalized_cat_slug === $normalized_search_slug) {
                        $category = $cat;
                        break;
                    }
                }
            }
        }
        
        if ($category) {
            // Verificar acceso por membresía antes de devolver
            if (function_exists('starter_get_category_min_membership')) {
                $min_level = starter_get_category_min_membership($category->term_id);
                if ($min_level > 0 && $user_level < $min_level) {
                    return new WP_Error(
                        'rest_forbidden',
                        'No tienes acceso a esta categoría.',
                        array('status' => 403)
                    );
                }
            }
            $category_data = starter_format_product_category($category);
            $lang = function_exists('starter_get_request_lang') ? starter_get_request_lang() : 'es';
            if ($lang !== 'es' && function_exists('starter_translate_category')) {
                $category_data = starter_translate_category($category_data, $lang);
            }
            return array($category_data);
        } else {
            return new WP_Error(
                'category_not_found', 
                'Categoría no encontrada: ' . $slug, 
                array(
                    'status' => 404,
                    'slug' => $slug,
                    'normalized_slug' => starter_normalize_slug($slug)
                )
            );
        }
    }
    
    // Obtener categorías
    $args = array(
        'taxonomy' => 'product_cat',
        'orderby' => 'name',
        'order' => 'ASC',
        'hide_empty' => $hide_empty,
        'parent' => $parent,
        'exclude' => get_option('default_product_cat', 0) // Excluir "Sin categoría"
    );
    
    $product_categories = get_terms($args);
    
    if (is_wp_error($product_categories)) {
        return new WP_Error('categories_error', 'Error al obtener las categorías de productos', array('status' => 500));
    }
    
    if (empty($product_categories)) {
        // Si no hay categorías con el padre especificado, intentar obtener todas
        if ($parent !== 0) {
            $args['parent'] = 0;
            $product_categories = get_terms($args);
            
            if (is_wp_error($product_categories) || empty($product_categories)) {
                return new WP_Error('no_categories', 'No hay categorías de productos disponibles', array('status' => 404));
            }
        } else {
            return new WP_Error('no_categories', 'No hay categorías de productos disponibles', array('status' => 404));
        }
    }
    
    $categories_data = array();
    $lang = function_exists('starter_get_request_lang') ? starter_get_request_lang() : 'es';
    
    foreach ($product_categories as $category) {
        // Filtrar categorías exclusivas por nivel de membresía
        if (function_exists('starter_get_category_min_membership')) {
            $min_level = starter_get_category_min_membership($category->term_id);
            if ($min_level > 0 && $user_level < $min_level) {
                continue;
            }
        }
        $cat_data = starter_format_product_category($category);
        if ($lang !== 'es' && function_exists('starter_translate_category')) {
            $cat_data = starter_translate_category($cat_data, $lang);
        }
        $categories_data[] = $cat_data;
    }
    
    return $categories_data;
}

/**
 * Formatear una categoría de producto para la API
 */
function starter_format_product_category($category) {
    $category_data = array(
        'id' => $category->term_id,
        'name' => $category->name,
        'slug' => $category->slug,
        'normalized_slug' => starter_normalize_slug($category->slug),
        'description' => $category->description,
        'count' => $category->count,
        'parent' => $category->parent,
        'link' => get_term_link($category->term_id, 'product_cat')
    );
    
    // Obtener imagen de la categoría
    $thumbnail_id = get_term_meta($category->term_id, 'thumbnail_id', true);
    if ($thumbnail_id) {
        $category_data['image'] = wp_get_attachment_url($thumbnail_id);
    }
    
    // Verificar si tiene subcategorías
    $has_children = get_terms(array(
        'taxonomy' => 'product_cat',
        'parent' => $category->term_id,
        'hide_empty' => false,
        'fields' => 'ids',
        'number' => 1 // Solo necesitamos saber si hay al menos una
    ));
    
    $category_data['has_children'] = !empty($has_children) && !is_wp_error($has_children);
    
    return $category_data;
}

/**
 * Normalizar un slug (eliminar acentos, convertir a minúsculas, etc.)
 */
function starter_normalize_slug($text) {
    if (empty($text)) {
        return '';
    }
    
    // Convertir a minúsculas
    $text = strtolower($text);
    
    // Eliminar acentos
    $text = remove_accents($text);
    
    // Eliminar caracteres especiales y reemplazar espacios por guiones
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/\s+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    
    // Eliminar guiones al principio y al final
    $text = trim($text, '-');
    
    return $text;
}
