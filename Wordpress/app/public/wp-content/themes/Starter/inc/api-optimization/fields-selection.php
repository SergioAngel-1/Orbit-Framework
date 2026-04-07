<?php
/**
 * API Optimization - Selección de campos (Fields Selection)
 * 
 * Este archivo implementa la funcionalidad similar a GraphQL para permitir
 * solicitar exactamente los campos necesarios de un recurso.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra el endpoint para consultas basadas en campos seleccionados
 */
function register_fields_selection_endpoint() {
    register_rest_route('starter/v1', '/query', array(
        'methods' => 'POST',
        'callback' => 'handle_fields_selection_query',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'register_fields_selection_endpoint');

/**
 * Procesa una consulta con selección de campos específicos
 * 
 * @param WP_REST_Request $request La solicitud REST
 * @return WP_REST_Response La respuesta con los datos solicitados
 */
function handle_fields_selection_query($request) {
    $params = $request->get_params();
    
    if (empty($params['resource']) || empty($params['fields'])) {
        return new WP_Error('invalid_query', 'Consulta inválida: falta recurso o campos', array('status' => 400));
    }
    
    $resource = $params['resource'];
    $fields = $params['fields'];
    $resource_id = $params['id'] ?? null;
    $query_params = $params['params'] ?? array();
    
    // Detect language for translations
    $lang = function_exists('starter_get_request_lang')
        ? starter_get_request_lang($params['lang'] ?? null)
        : 'es';
    
    // Procesar la consulta
    switch ($resource) {
        case 'products':
            $result = get_products_with_fields($fields, $query_params, $resource_id);
            // Apply translations to products
            if ($lang !== 'es' && $result && function_exists('starter_translate_product')) {
                if (is_array($result) && isset($result[0]) && is_array($result[0])) {
                    if (function_exists('starter_preload_category_translation_meta')) {
                        starter_preload_category_translation_meta($result);
                    }
                    $result = array_map(function($p) use ($lang) {
                        return starter_translate_product($p, $lang);
                    }, $result);
                } elseif (is_array($result) && isset($result['id'])) {
                    $result = starter_translate_product($result, $lang);
                }
            }
            break;
        case 'categories':
            $result = get_categories_with_fields($fields, $query_params, $resource_id);
            // Apply translations to categories
            if ($lang !== 'es' && $result && function_exists('starter_translate_category')) {
                if (is_array($result) && isset($result[0]) && is_array($result[0])) {
                    $result = array_map(function($c) use ($lang) {
                        return starter_translate_category($c, $lang);
                    }, $result);
                } elseif (is_array($result) && isset($result['id'])) {
                    $result = starter_translate_category($result, $lang);
                }
            }
            break;
        case 'users':
            $result = get_users_with_fields($fields, $query_params, $resource_id);
            break;
        default:
            return new WP_Error('invalid_resource', 'Recurso no soportado', array('status' => 400));
    }
    
    return rest_ensure_response($result);
}

/**
 * Determina el tipo de caché basado en el recurso
 * 
 * @param string $resource El tipo de recurso
 * @param string|null $resource_id El ID del recurso, si existe
 * @return string El tipo de caché
 */
function determine_cache_type($resource, $resource_id) {
    switch ($resource) {
        case 'products':
            return $resource_id ? 'product' : 'products';
        case 'categories':
            return $resource_id ? 'category' : 'categories';
        case 'users':
            return 'user';
        default:
            return $resource;
    }
}

/**
 * Obtiene productos con campos específicos
 * 
 * @param array $fields Los campos a incluir en la respuesta
 * @param array $query_params Parámetros adicionales para la consulta
 * @param int|null $product_id ID del producto si se solicita uno específico
 * @return array Los datos del producto o productos con los campos solicitados
 */
function get_products_with_fields($fields, $query_params, $product_id = null) {
    // Si se especifica un ID, obtener un solo producto
    if ($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }
        return extract_product_fields($product, $fields);
    }
    
    // Parámetros para la consulta
    $args = array(
        'status' => 'publish',
        'limit' => $query_params['per_page'] ?? 10,
        'page' => $query_params['page'] ?? 1,
    );
    
    // Añadir filtros adicionales
    if (!empty($query_params['category'])) {
        $args['category'] = array($query_params['category']);
    }
    
    if (!empty($query_params['search'])) {
        $args['s'] = $query_params['search'];
    }
    
    // Ordenamiento
    if (!empty($query_params['orderby'])) {
        $args['orderby'] = $query_params['orderby'];
        $args['order'] = $query_params['order'] ?? 'DESC';
    }
    
    // Obtener productos
    $products = wc_get_products($args);
    
    // Extraer los campos solicitados de cada producto
    $result = array();
    foreach ($products as $product) {
        $result[] = extract_product_fields($product, $fields);
    }
    
    return $result;
}

/**
 * Extrae campos específicos de un producto
 * 
 * @param WC_Product $product El objeto producto
 * @param array $fields Los campos a extraer
 * @return array Los datos del producto con los campos solicitados
 */
function extract_product_fields($product, $fields) {
    $data = array();
    
    // Procesar cada campo solicitado
    foreach ($fields as $field) {
        switch ($field) {
            case 'id':
                $data['id'] = $product->get_id();
                break;
            case 'name':
                $data['name'] = $product->get_name();
                break;
            case 'price':
                $data['price'] = $product->get_price();
                break;
            case 'regular_price':
                $data['regular_price'] = $product->get_regular_price();
                break;
            case 'sale_price':
                $data['sale_price'] = $product->get_sale_price();
                break;
            case 'description':
                $data['description'] = $product->get_description();
                break;
            case 'short_description':
                $data['short_description'] = $product->get_short_description();
                break;
            case 'sku':
                $data['sku'] = $product->get_sku();
                break;
            case 'stock_status':
                $data['stock_status'] = $product->get_stock_status();
                break;
            case 'stock_quantity':
                $data['stock_quantity'] = $product->get_stock_quantity();
                break;
            case 'categories':
                $data['categories'] = array();
                $terms = get_the_terms($product->get_id(), 'product_cat');
                if ($terms && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $data['categories'][] = array(
                            'id' => $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug
                        );
                    }
                }
                break;
            case 'images':
                $data['images'] = array();
                
                // Imagen principal
                if ($product->get_image_id()) {
                    $image = wp_get_attachment_image_src($product->get_image_id(), 'full');
                    if ($image) {
                        $data['images'][] = array(
                            'id' => $product->get_image_id(),
                            'src' => $image[0],
                            'alt' => get_post_meta($product->get_image_id(), '_wp_attachment_image_alt', true)
                        );
                    }
                }
                
                // Imágenes de galería
                $gallery_ids = $product->get_gallery_image_ids();
                foreach ($gallery_ids as $id) {
                    $image = wp_get_attachment_image_src($id, 'full');
                    if ($image) {
                        $data['images'][] = array(
                            'id' => $id,
                            'src' => $image[0],
                            'alt' => get_post_meta($id, '_wp_attachment_image_alt', true)
                        );
                    }
                }
                break;
            case 'attributes':
                $data['attributes'] = array();
                $attributes = $product->get_attributes();
                foreach ($attributes as $attribute) {
                    if ($attribute->is_taxonomy()) {
                        $terms = wp_get_post_terms($product->get_id(), $attribute->get_name());
                        $options = array();
                        foreach ($terms as $term) {
                            $options[] = $term->name;
                        }
                    } else {
                        $options = $attribute->get_options();
                    }
                    $data['attributes'][] = array(
                        'name' => wc_attribute_label($attribute->get_name()),
                        'options' => $options
                    );
                }
                break;
        }
    }
    
    return $data;
}

/**
 * Obtiene categorías con campos específicos
 * 
 * @param array $fields Los campos a incluir en la respuesta
 * @param array $query_params Parámetros adicionales para la consulta
 * @param int|null $category_id ID de la categoría si se solicita una específica
 * @return array Los datos de la categoría o categorías con los campos solicitados
 */
function get_categories_with_fields($fields, $query_params, $category_id = null) {
    // Implementación similar a productos, para categorías
    // En una versión futura, implementar la extracción de campos para categorías
    return array(); // Implementación simplificada
}

/**
 * Obtiene usuarios con campos específicos
 * 
 * @param array $fields Los campos a incluir en la respuesta
 * @param array $query_params Parámetros adicionales para la consulta
 * @param int|null $user_id ID del usuario si se solicita uno específico
 * @return array Los datos del usuario o usuarios con los campos solicitados
 */
function get_users_with_fields($fields, $query_params, $user_id = null) {
    // Implementación similar para usuarios
    // En una versión futura, implementar la extracción de campos para usuarios
    return array(); // Implementación simplificada
}
