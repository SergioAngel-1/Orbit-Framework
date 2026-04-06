<?php
/**
 * API REST para grillas publicitarias
 * 
 * Sistema unificado de grillas por nivel de membresía.
 * El sistema CPT legacy ha sido deprecado.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar helpers del nuevo sistema si existen
if (file_exists(dirname(__FILE__) . '/admin/helpers.php')) {
    require_once dirname(__FILE__) . '/admin/helpers.php';
    require_once dirname(__FILE__) . '/admin/config.php';
}

/**
 * Registrar endpoints de la API REST para grillas publicitarias
 */
function starter_register_promotional_grid_rest_route() {
    // Endpoint para obtener grilla por defecto
    register_rest_route('starter/v1', '/promotional-grid', array(
        'methods'  => 'GET',
        'callback' => 'starter_get_promotional_grid_rest',
        'permission_callback' => '__return_true',
    ));
    
    // Endpoint para obtener grilla por categoría
    register_rest_route('starter/v1', '/promotional-grid/category/(?P<id>\d+)', array(
        'methods'  => 'GET',
        'callback' => 'starter_get_promotional_grid_by_category_rest',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
        ),
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'starter_register_promotional_grid_rest_route');

/**
 * Verificar si el nuevo sistema de grillas por nivel está activo
 */
function starter_is_new_grid_system_active() {
    return function_exists('fipg_get_grids_by_level') && 
           !empty(fipg_get_grids_by_level());
}

/**
 * Obtener grilla del nuevo sistema por nivel
 * 
 * Respeta el modo de visibilidad:
 * - cascade: Este nivel y superiores pueden ver la grilla
 * - exact: Solo este nivel específico puede ver la grilla
 * 
 * Se prioriza la grilla del nivel más alto disponible para el usuario
 */
function starter_get_grid_from_new_system($user_level, $type = 'default', $category_id = null) {
    if (!function_exists('fipg_get_grids_by_level')) {
        return null;
    }
    
    $grids_by_level = fipg_get_grids_by_level();
    $found_grid = null;
    $found_level = -1;
    
    // Buscar en todos los niveles desde 0 hasta el nivel del usuario
    for ($level = 0; $level <= $user_level; $level++) {
        if (!isset($grids_by_level[$level])) continue;
        
        // Verificar exclusiones propias de este nivel
        $excluded = fipg_get_excluded_grids($level);
        
        foreach ($grids_by_level[$level] as $grid) {
            // Saltar si está excluida
            if (in_array($grid['id'], $excluded)) continue;
            
            // Saltar si no está habilitada
            if (empty($grid['enabled'])) continue;
            
            // Filtrar por tipo
            if ($type === 'default' && $grid['type'] !== 'default') continue;
            if ($type === 'category' && ($grid['type'] !== 'category' || $grid['category_id'] != $category_id)) continue;
            
            // Verificar modo de visibilidad
            $visibility_mode = $grid['visibility_mode'] ?? 'cascade';
            
            if ($visibility_mode === 'exact') {
                // Modo exacto: solo visible si el usuario es exactamente de este nivel
                if ($user_level !== $level) continue;
            }
            // Modo cascade: visible para este nivel y superiores (ya filtrado por el loop)
            
            // Encontrada - guardar si es de nivel más alto que la anterior
            if ($level > $found_level) {
                $found_grid = $grid;
                $found_grid['from_level'] = $level;
                $found_level = $level;
            }
        }
    }
    
    // Si buscamos categoría y no encontramos, buscar grilla por defecto
    if (!$found_grid && $type === 'category') {
        return starter_get_grid_from_new_system($user_level, 'default');
    }
    
    return $found_grid;
}

/**
 * Convertir grilla del nuevo sistema a formato de respuesta API
 */
function starter_format_new_grid_response($grid, $category_id = null) {
    if (!$grid) {
        return null;
    }
    
    $products = array();
    foreach ($grid['products'] as $product_id) {
        $product = wc_get_product($product_id);
        if (!$product) continue;

        // Calcular nivel mínimo requerido del producto (máximo entre sus categorías)
        $product_min_level = 0;
        $product_categories = get_the_terms($product_id, 'product_cat');
        if ($product_categories && !is_wp_error($product_categories) && function_exists('starter_get_category_min_membership')) {
            foreach ($product_categories as $cat) {
                $cat_level = starter_get_category_min_membership($cat->term_id);
                if ($cat_level > $product_min_level) {
                    $product_min_level = $cat_level;
                }
            }
        }

        // Categorías para el producto (incluyendo min_membership_level cuando aplique)
        $categories = array();
        if ($product_categories && !is_wp_error($product_categories)) {
            foreach ($product_categories as $cat) {
                $cat_data = array(
                    'id' => $cat->term_id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                );
                if (function_exists('starter_get_category_min_membership')) {
                    $cat_data['min_membership_level'] = starter_get_category_min_membership($cat->term_id);
                }
                $categories[] = $cat_data;
            }
        }
        
        $product_data = array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'price' => $product->get_price(),
            'regularPrice' => $product->get_regular_price(),
            'salePrice' => $product->get_sale_price(),
            'onSale' => $product->is_on_sale(),
            'permalink' => get_permalink($product->get_id()),
            'type' => $product->get_type(),
            'stockStatus' => $product->get_stock_status(),
            'categories' => $categories,
            'membership_required' => $product_min_level,
        );
        
        // Imagen
        if (has_post_thumbnail($product->get_id())) {
            $image_id = get_post_thumbnail_id($product->get_id());
            $product_data['image'] = wp_get_attachment_image_url($image_id, 'medium');
            $product_data['imageLarge'] = wp_get_attachment_image_url($image_id, 'large');
        } else {
            $product_data['image'] = wc_placeholder_img_src('medium');
            $product_data['imageLarge'] = wc_placeholder_img_src('large');
        }
        
        // Descuento
        if ($product->is_on_sale() && $product->get_regular_price()) {
            $regular = (float) $product->get_regular_price();
            $sale = (float) $product->get_sale_price();
            if ($regular > 0) {
                $product_data['discountPercentage'] = round(100 - ($sale / $regular * 100));
            }
        }
        
        $products[] = $product_data;
    }
    
    $membership_levels = fipg_get_membership_levels();
    $level_info = $membership_levels[$grid['from_level']] ?? null;
    
    return array(
        'success' => true,
        'gridId' => $grid['id'],
        'title' => $grid['title'] ?: 'Productos destacados',
        'categoryId' => $category_id ? (int) $category_id : null,
        'products' => $products,
        'productsCount' => count($products),
        'isConfiguredGrid' => true,
        'isDefaultGrid' => $grid['type'] === 'default',
        'usingSpecific' => $grid['type'] === 'category',
        'membershipInfo' => $level_info ? array(
            'minLevel' => $grid['from_level'],
            'levelName' => $level_info['name'],
            'levelIcon' => $level_info['icon'],
            'levelColor' => $level_info['color'],
        ) : null,
        'source' => 'new_system',
    );
}

/**
 * Callback para el endpoint de la API REST por defecto
 */
function starter_get_promotional_grid_rest() {
    $user_membership_level = starter_get_grid_user_membership_level();
    
    // Usar sistema de grillas por nivel
    $grid = starter_get_grid_from_new_system($user_membership_level, 'default');
    
    if ($grid) {
        $response = starter_format_new_grid_response($grid);
        if ($response) {
            return rest_ensure_response($response);
        }
    }
    
    // No hay grilla configurada
    return rest_ensure_response(array(
        'success' => true,
        'gridId' => 0,
        'title' => __('No hay productos promocionales', 'starter'),
        'products' => array(),
        'productsCount' => 0,
        'noConfiguredGrid' => true,
        'isConfiguredGrid' => false,
    ));
}

/**
 * Callback para el endpoint de la API REST por categoría
 */
function starter_get_promotional_grid_by_category_rest($request) {
    $category_id = (int) $request['id'];
    $user_membership_level = starter_get_grid_user_membership_level();
    
    // Validar que la categoría existe
    $category = get_term($category_id, 'product_cat');
    if (is_wp_error($category) || !$category) {
        return new WP_Error(
            'category_not_found',
            __('La categoría especificada no existe.', 'starter'),
            array('status' => 404)
        );
    }

    // Buscar grilla específica para esta categoría
    $grid = starter_get_grid_from_new_system($user_membership_level, 'category', $category_id);
    
    if ($grid) {
        $response = starter_format_new_grid_response($grid, $category_id);
        if ($response) {
            $response['categoryName'] = $category->name;
            return rest_ensure_response($response);
        }
    }
    
    // No hay grilla configurada para esta categoría
    return rest_ensure_response(array(
        'success' => true,
        'gridId' => 0,
        'title' => __('Productos destacados', 'starter'),
        'categoryId' => $category_id,
        'categoryName' => $category->name,
        'products' => array(),
        'productsCount' => 0,
        'noConfiguredGrid' => true,
        'isConfiguredGrid' => false,
        'isDefaultGrid' => true,
    ));
}
