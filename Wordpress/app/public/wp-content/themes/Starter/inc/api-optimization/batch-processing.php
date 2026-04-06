<?php
/**
 * API Optimization - Procesamiento por lotes (Batch Processing)
 * 
 * Este archivo implementa la funcionalidad de procesamiento por lotes,
 * permitiendo ejecutar múltiples solicitudes de API en una sola llamada.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra el endpoint para procesamiento por lotes
 */
function register_batch_endpoint() {
    register_rest_route('starter/v1', '/batch', array(
        'methods' => 'POST',
        'callback' => 'handle_batch_requests',
        'permission_callback' => '__return_true', // La autenticación se verifica por solicitud individual
    ));
}
add_action('rest_api_init', 'register_batch_endpoint');

/**
 * Maximum number of requests allowed in a single batch
 */
define('STARTER_BATCH_MAX_REQUESTS', 10);

/**
 * Procesa múltiples solicitudes de API en un solo request
 * 
 * SEGURIDAD: Solo permite solicitudes GET para evitar bypass de CSRF.
 * rest_do_request() no re-ejecuta rest_pre_dispatch, lo que significa que
 * los filtros CSRF no se aplican a inner requests. Restringir a GET
 * garantiza que solo operaciones de lectura puedan batched.
 * 
 * @param WP_REST_Request $request La solicitud REST
 * @return WP_REST_Response La respuesta con los resultados de todas las solicitudes
 */
function handle_batch_requests($request) {
    $params = $request->get_json_params();
    
    if (empty($params['requests']) || !is_array($params['requests'])) {
        return new WP_Error('invalid_batch', 'No hay solicitudes válidas en el lote', array('status' => 400));
    }
    
    $requests = $params['requests'];
    
    // Limitar tamaño del batch para prevenir abuso
    if (count($requests) > STARTER_BATCH_MAX_REQUESTS) {
        return new WP_Error(
            'batch_too_large', 
            sprintf('Máximo %d solicitudes por lote', STARTER_BATCH_MAX_REQUESTS), 
            array('status' => 400)
        );
    }
    
    $responses = array();
    
    // Procesar cada solicitud
    foreach ($requests as $req) {
        $req_id = isset($req['id']) ? $req['id'] : 'unknown';
        
        // Verificar campos obligatorios
        if (empty($req['method']) || empty($req['path'])) {
            $responses[] = array(
                'id' => $req_id,
                'status' => 400,
                'data' => array('error' => 'Solicitud inválida: falta método o ruta')
            );
            continue;
        }
        
        // SEGURIDAD: Solo permitir GET para evitar bypass de CSRF en operaciones mutantes
        $method = strtoupper($req['method']);
        if ($method !== 'GET') {
            $responses[] = array(
                'id' => $req_id,
                'status' => 405,
                'data' => array('error' => 'Batch solo permite solicitudes GET')
            );
            continue;
        }
        
        // Normalizar path
        $path = ltrim($req['path'], '/');
        
        // Crear una instancia de WP_REST_Request
        $inner_request = new WP_REST_Request('GET', '/' . $path);
        
        // Añadir parámetros si están presentes
        if (!empty($req['params']) && is_array($req['params'])) {
            foreach ($req['params'] as $param_name => $param_value) {
                $inner_request->set_param($param_name, $param_value);
            }
        }
        
        // Despachar la solicitud
        // Nota: rest_do_request() hereda el usuario autenticado del proceso actual
        // (establecido por JWT middleware via rest_pre_dispatch)
        $response = rest_do_request($inner_request);
        
        // Preparar respuesta para este ítem del lote
        $resp_item = array(
            'id' => $req_id,
            'status' => $response->get_status(),
        );
        
        // Manejar éxito o error
        if ($response->is_error()) {
            $resp_item['data'] = array(
                'error' => $response->get_error_message(),
                'code' => $response->get_error_code()
            );
        } else {
            $resp_item['data'] = $response->get_data();
        }
        
        $responses[] = $resp_item;
    }
    
    return rest_ensure_response(array(
        'responses' => $responses
    ));
}
