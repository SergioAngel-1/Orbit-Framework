<?php
/**
 * Funciones auxiliares para grillas publicitarias
 * 
 * Este archivo contiene funciones auxiliares para obtener y procesar
 * datos de las grillas publicitarias.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Función auxiliar para obtener productos de una grilla
 */
function starter_get_products_from_grid($grid_id) {
    $products = array();
    
    // Obtener IDs de productos
    $product_ids = get_post_meta($grid_id, '_promotional_grid_products', true);
    
    // Asegurarnos que product_ids sea un array
    if (!is_array($product_ids)) {
        if (is_string($product_ids) && !empty($product_ids)) {
            if (strpos($product_ids, 'a:') === 0) {
                $maybe_array = @unserialize($product_ids);
                $product_ids = ($maybe_array !== false) ? $maybe_array : array($product_ids);
            } else {
                $product_ids = array($product_ids);
            }
        } else if (empty($product_ids)) {
            $product_ids = array();
        } else {
            $product_ids = array($product_ids);
        }
    }
    
    if (!empty($product_ids)) {
        foreach ($product_ids as $product_id) {
            if (empty($product_id)) continue;
            
            $product_id = intval($product_id);
            $product = wc_get_product($product_id);
            
            if (!$product) continue;
            
            // Obtener datos del producto
            $product_data = array(
                'id'          => $product->get_id(),
                'name'        => $product->get_name(),
                'price'       => $product->get_price(),
                'regularPrice' => $product->get_regular_price(),
                'salePrice'   => $product->get_sale_price(),
                'onSale'      => $product->is_on_sale(),
                'permalink'   => get_permalink($product->get_id()),
                'type'        => $product->get_type(),
                'stockStatus' => $product->get_stock_status(),
            );
            
            // Añadir imagen destacada si existe
            if (has_post_thumbnail($product->get_id())) {
                $image_id = get_post_thumbnail_id($product->get_id());
                $image_url = wp_get_attachment_image_url($image_id, 'medium');
                $image_url_large = wp_get_attachment_image_url($image_id, 'large');
                
                $product_data['image'] = $image_url;
                $product_data['imageLarge'] = $image_url_large;
            } else {
                $product_data['image'] = wc_placeholder_img_src('medium');
                $product_data['imageLarge'] = wc_placeholder_img_src('large');
            }
            
            // Añadir porcentaje de descuento si está en oferta
            if ($product->is_on_sale() && $product->get_regular_price()) {
                $regular_price = (float) $product->get_regular_price();
                $sale_price = (float) $product->get_sale_price();
                
                if ($regular_price > 0) {
                    $discount_percentage = round(100 - ($sale_price / $regular_price * 100));
                    $product_data['discountPercentage'] = $discount_percentage;
                }
            }
            
            // Añadir categorías del producto
            $categories = array();
            $terms = get_the_terms($product->get_id(), 'product_cat');
            
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[] = array(
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            }
            
            $product_data['categories'] = $categories;
            
            $products[] = $product_data;
        }
    }
    
    return $products;
}

/**
 * Obtener nivel de membresía del usuario actual para grillas
 * CRÍTICO: Usa verificación JWT para evitar problemas con cookies de sesión
 */
function starter_get_grid_user_membership_level() {
    // Usar helper JWT si está disponible
    if (function_exists('starter_get_jwt_user_membership_level')) {
        return starter_get_jwt_user_membership_level();
    }
    
    // Fallback al método tradicional
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return 0;
    }
    
    if (function_exists('starter_get_user_membership_level')) {
        return starter_get_user_membership_level($user_id);
    }
    
    return 0;
}

/**
 * Verificar si el usuario puede ver una grilla según su membresía
 */
function starter_user_can_view_grid($grid_id, $user_membership_level) {
    $min_membership = get_post_meta($grid_id, '_promotional_grid_min_membership', true);
    $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
    $membership_mode = get_post_meta($grid_id, '_promotional_grid_membership_mode', true);
    $membership_mode = $membership_mode ?: 'cascade';
    
    // Nivel público: todos pueden ver
    if ($min_membership === 0) {
        return true;
    }
    
    // Modo exacto: solo el nivel específico puede ver
    if ($membership_mode === 'exact') {
        return ($user_membership_level === $min_membership);
    }
    
    // Modo cascada: este nivel y superiores
    return ($user_membership_level >= $min_membership);
}

/**
 * Obtener información de membresía de una grilla
 */
function starter_get_grid_membership_info($grid_id) {
    $min_membership = get_post_meta($grid_id, '_promotional_grid_min_membership', true);
    $min_membership = $min_membership !== '' ? intval($min_membership) : 0;
    $membership_mode = get_post_meta($grid_id, '_promotional_grid_membership_mode', true);
    $membership_mode = $membership_mode ?: 'cascade';
    
    $info = array(
        'minLevel' => $min_membership,
        'mode' => $membership_mode,
    );
    
    if ($min_membership > 0 && class_exists('Starter_Memberships')) {
        $level_info = Starter_Memberships::get_membership_level($min_membership);
        $info['levelName'] = $level_info['name'];
        $info['levelIcon'] = $level_info['icon'];
        $info['levelColor'] = $level_info['color'];
    }
    
    return $info;
}

/**
 * Encontrar la grilla por defecto más apropiada según el nivel de membresía del usuario
 * 
 * Permite múltiples grillas "por defecto" (sin categoría), cada una con diferente
 * nivel de membresía. Selecciona la grilla más apropiada para el nivel del usuario:
 * - Primero busca una grilla con modo exacto que coincida con el nivel del usuario
 * - Si no existe, busca la grilla con el nivel más alto que el usuario pueda acceder (cascada)
 * - Si no hay ninguna, retorna null
 * 
 * @param int $user_membership_level Nivel de membresía del usuario
 * @return int|null ID de la grilla encontrada o null si no hay ninguna
 */
function starter_find_best_default_grid($user_membership_level) {
    // Buscar todas las grillas activas por defecto (sin categoría asignada)
    $args = array(
        'post_type'      => 'promotional_grid',
        'posts_per_page' => -1,
        'meta_query'     => array(
            'relation' => 'AND',
            array(
                'key'   => '_promotional_grid_active',
                'value' => '1',
            ),
            array(
                'relation' => 'OR',
                array(
                    'key'     => '_promotional_grid_category',
                    'value'   => '',
                    'compare' => '=',
                ),
                array(
                    'key'     => '_promotional_grid_category',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        ),
    );
    
    $query = new WP_Query($args);
    $found_grid = null;
    $best_match_level = -1;
    $candidate_grids = array();
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $grid_id = get_the_ID();
            
            // Verificar acceso por membresía
            if (starter_user_can_view_grid($grid_id, $user_membership_level)) {
                $grid_min_level = get_post_meta($grid_id, '_promotional_grid_min_membership', true);
                $grid_min_level = $grid_min_level !== '' ? intval($grid_min_level) : 0;
                $grid_mode = get_post_meta($grid_id, '_promotional_grid_membership_mode', true) ?: 'cascade';
                
                $candidate_grids[] = array(
                    'id' => $grid_id,
                    'min_level' => $grid_min_level,
                    'mode' => $grid_mode
                );
            }
        }
        wp_reset_postdata();
    }
    
    // Seleccionar la grilla más apropiada para el nivel del usuario
    if (!empty($candidate_grids)) {
        // Primero buscar una grilla con modo exacto que coincida con el nivel del usuario
        foreach ($candidate_grids as $grid) {
            if ($grid['mode'] === 'exact' && $grid['min_level'] === $user_membership_level) {
                $found_grid = $grid['id'];
                break;
            }
        }
        
        // Si no hay coincidencia exacta, buscar la grilla con el nivel más alto en cascada
        if (!$found_grid) {
            foreach ($candidate_grids as $grid) {
                if ($grid['mode'] === 'cascade' && $grid['min_level'] > $best_match_level) {
                    $best_match_level = $grid['min_level'];
                    $found_grid = $grid['id'];
                }
            }
        }
        
        // Si aún no hay grilla, usar la primera disponible (nivel 0/público)
        if (!$found_grid && !empty($candidate_grids)) {
            $found_grid = $candidate_grids[0]['id'];
        }
    }
    
    return $found_grid;
}

/**
 * Función auxiliar para obtener los productos de la grilla por defecto
 */
function starter_get_default_promotional_grid_products() {
    $products = array();
    
    // Obtener productos en oferta
    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => 3,
        'meta_query'     => array(
            'relation' => 'OR',
            array(
                'key'     => '_sale_price',
                'value'   => '',
                'compare' => '!=',
            ),
            array(
                'key'     => '_featured',
                'value'   => 'yes',
            ),
        ),
        'orderby'        => 'rand',
    );
    
    $query = new WP_Query($args);
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product_id = get_the_ID();
            $product = wc_get_product($product_id);
            
            if (!$product) {
                continue;
            }
            
            // Obtener datos del producto
            $product_data = array(
                'id'          => $product->get_id(),
                'name'        => $product->get_name(),
                'price'       => $product->get_price(),
                'regularPrice' => $product->get_regular_price(),
                'salePrice'   => $product->get_sale_price(),
                'onSale'      => $product->is_on_sale(),
                'permalink'   => get_permalink($product->get_id()),
                'type'        => $product->get_type(),
                'stockStatus' => $product->get_stock_status(),
            );
            
            // Añadir imagen destacada si existe
            if (has_post_thumbnail($product->get_id())) {
                $image_id = get_post_thumbnail_id($product->get_id());
                $image_url = wp_get_attachment_image_url($image_id, 'medium');
                $image_url_large = wp_get_attachment_image_url($image_id, 'large');
                
                $product_data['image'] = $image_url;
                $product_data['imageLarge'] = $image_url_large;
            } else {
                $product_data['image'] = wc_placeholder_img_src('medium');
                $product_data['imageLarge'] = wc_placeholder_img_src('large');
            }
            
            // Añadir porcentaje de descuento si está en oferta
            if ($product->is_on_sale() && $product->get_regular_price()) {
                $regular_price = (float) $product->get_regular_price();
                $sale_price = (float) $product->get_sale_price();
                
                if ($regular_price > 0) {
                    $discount_percentage = round(100 - ($sale_price / $regular_price * 100));
                    $product_data['discountPercentage'] = $discount_percentage;
                }
            }
            
            // Añadir a la lista de productos
            $products[] = $product_data;
        }
        
        wp_reset_postdata();
    }
    
    return $products;
}
