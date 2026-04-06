<?php
/**
 * API Optimization - Filtros de optimización
 * 
 * Este archivo contiene filtros para optimizar las respuestas de la API REST,
 * incluyendo filtrado de campos, paginación eficiente y lazy loading.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Optimiza el endpoint de productos para admitir paginación eficiente y lazy loading
 * 
 * @param WP_REST_Response $response La respuesta original
 * @param WP_REST_Server $handler El manejador de la solicitud
 * @param WP_REST_Request $request La solicitud original
 * @return WP_REST_Response La respuesta optimizada
 */
function starter_optimize_products_endpoint($response, $handler, $request) {
    // Verificar si es el endpoint de productos
    if ($request->get_route() !== '/wc/v3/products') {
        return $response;
    }
    
    // Asegurarse de que sea una respuesta exitosa
    if (is_wp_error($response) || $response->get_status() !== 200) {
        return $response;
    }
    
    // Verificar si se solicitan campos específicos para optimizar respuesta
    $fields = $request->get_param('_fields');
    if (!$fields) {
        return $response;
    }
    
    // Convertir string de campos a array
    $fields_array = explode(',', $fields);
    
    // Filtrar sólo los campos solicitados
    $data = $response->get_data();
    $filtered_data = array();
    
    foreach ($data as $item) {
        $filtered_item = array();
        foreach ($fields_array as $field) {
            $field = trim($field);
            if (isset($item[$field])) {
                $filtered_item[$field] = $item[$field];
            }
        }
        $filtered_data[] = $filtered_item;
    }
    
    // Actualizar la respuesta con los datos filtrados
    $response->set_data($filtered_data);
    
    return $response;
}

/**
 * Optimiza las respuestas de categorías para incluir solo los campos solicitados
 * 
 * @param WP_REST_Response $response La respuesta original
 * @param WP_REST_Server $handler El manejador de la solicitud
 * @param WP_REST_Request $request La solicitud original
 * @return WP_REST_Response La respuesta optimizada
 */
function starter_optimize_categories_endpoint($response, $handler, $request) {
    // Verificar si es el endpoint de categorías
    if (!preg_match('#^/wc/v3/products/categories#', $request->get_route())) {
        return $response;
    }
    
    // Asegurarse de que sea una respuesta exitosa
    if (is_wp_error($response) || $response->get_status() !== 200) {
        return $response;
    }
    
    // Verificar si se solicitan campos específicos para optimizar respuesta
    $fields = $request->get_param('_fields');
    if (!$fields) {
        return $response;
    }
    
    // Convertir string de campos a array
    $fields_array = explode(',', $fields);
    
    // Filtrar sólo los campos solicitados
    $data = $response->get_data();
    
    // Si es un solo objeto (categoría específica)
    if (isset($data['id'])) {
        $filtered_data = array();
        foreach ($fields_array as $field) {
            $field = trim($field);
            if (isset($data[$field])) {
                $filtered_data[$field] = $data[$field];
            }
        }
        $response->set_data($filtered_data);
    } 
    // Si es un array de objetos (listado de categorías)
    else if (is_array($data)) {
        $filtered_data = array();
        foreach ($data as $item) {
            $filtered_item = array();
            foreach ($fields_array as $field) {
                $field = trim($field);
                if (isset($item[$field])) {
                    $filtered_item[$field] = $item[$field];
                }
            }
            $filtered_data[] = $filtered_item;
        }
        $response->set_data($filtered_data);
    }
    
    return $response;
}

/**
 * Optimiza las respuestas de usuarios para incluir solo los campos solicitados
 * 
 * @param WP_REST_Response $response La respuesta original
 * @param WP_REST_Server $handler El manejador de la solicitud
 * @param WP_REST_Request $request La solicitud original
 * @return WP_REST_Response La respuesta optimizada
 */
function starter_optimize_users_endpoint($response, $handler, $request) {
    // Verificar si es el endpoint de usuarios
    if (!preg_match('#^/wp/v2/users#', $request->get_route())) {
        return $response;
    }
    
    // Asegurarse de que sea una respuesta exitosa
    if (is_wp_error($response) || $response->get_status() !== 200) {
        return $response;
    }
    
    // Verificar si se solicitan campos específicos para optimizar respuesta
    $fields = $request->get_param('_fields');
    if (!$fields) {
        return $response;
    }
    
    // Convertir string de campos a array
    $fields_array = explode(',', $fields);
    
    // Filtrar sólo los campos solicitados
    $data = $response->get_data();
    
    // Si es un solo objeto (usuario específico)
    if (isset($data['id'])) {
        $filtered_data = array();
        foreach ($fields_array as $field) {
            $field = trim($field);
            if (isset($data[$field])) {
                $filtered_data[$field] = $data[$field];
            }
        }
        $response->set_data($filtered_data);
    } 
    // Si es un array de objetos (listado de usuarios)
    else if (is_array($data)) {
        $filtered_data = array();
        foreach ($data as $item) {
            $filtered_item = array();
            foreach ($fields_array as $field) {
                $field = trim($field);
                if (isset($item[$field])) {
                    $filtered_item[$field] = $item[$field];
                }
            }
            $filtered_data[] = $filtered_item;
        }
        $response->set_data($filtered_data);
    }
    
    return $response;
}

// Estos filtros se registran condicionalmente en starter_register_api_optimization_hooks()
