<?php
/**
 * Popups - REST API
 * 
 * Este archivo contiene la implementación de los endpoints REST API
 * para acceder a los popups desde el frontend.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoints REST API para popups
 */
function starter_register_popup_rest_routes() {
    // Endpoint para obtener popups activos para el usuario actual
    register_rest_route('starter/v1', '/popups', array(
        'methods' => 'GET',
        'callback' => 'starter_get_user_popups',
        'permission_callback' => '__return_true',
    ));
    
    // Endpoint para obtener popup por tipo
    register_rest_route('starter/v1', '/popups/(?P<type>[a-zA-Z0-9_-]+)', array(
        'methods' => 'GET',
        'callback' => 'starter_get_popup_by_type',
        'permission_callback' => '__return_true',
        'args' => array(
            'type' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_string($param);
                }
            ),
        ),
    ));
    
    // Endpoint para registrar interacción con popup
    // Permite acceso anónimo para login_prompt, pero verifica en el callback
    register_rest_route('starter/v1', '/popups/(?P<id>\d+)/interact', array(
        'methods' => 'POST',
        'callback' => 'starter_popup_interaction',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
    
    // Endpoint para aceptar/rechazar membresía por antigüedad
    register_rest_route('starter/v1', '/popups/legacy-membership/respond', array(
        'methods' => 'POST',
        'callback' => 'starter_legacy_membership_respond',
        'permission_callback' => function() {
            return is_user_logged_in();
        },
    ));
}
add_action('rest_api_init', 'starter_register_popup_rest_routes', 10);

/**
 * Tiempo de caché para popups activos (en segundos)
 */
define('STARTER_POPUPS_CACHE_TTL', 300); // 5 minutos

/**
 * Obtener popups activos desde caché o base de datos
 * 
 * @return array Array de objetos WP_Post
 */
function starter_get_active_popups_cached() {
    $cache_key = 'starter_active_popups';
    $cached = get_transient($cache_key);
    
    if ($cached !== false) {
        return $cached;
    }
    
    $args = array(
        'post_type' => 'starter_popup',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => array(
            array(
                'key' => '_popup_active',
                'value' => '1',
                'compare' => '=',
            ),
        ),
    );
    
    $popups = get_posts($args);
    
    // Guardar en caché
    set_transient($cache_key, $popups, STARTER_POPUPS_CACHE_TTL);
    
    return $popups;
}

/**
 * Invalidar caché de popups
 */
function starter_invalidate_popups_cache() {
    delete_transient('starter_active_popups');
}

/**
 * Hook para invalidar caché cuando se guarda un popup
 */
add_action('save_post_starter_popup', 'starter_invalidate_popups_cache');
add_action('delete_post', function($post_id) {
    if (get_post_type($post_id) === 'starter_popup') {
        starter_invalidate_popups_cache();
    }
});
add_action('trashed_post', function($post_id) {
    if (get_post_type($post_id) === 'starter_popup') {
        starter_invalidate_popups_cache();
    }
});

/**
 * Callback para obtener popups del usuario actual
 */
function starter_get_user_popups() {
    $user_id = get_current_user_id();
    
    // Obtener popups activos desde caché
    $popups = starter_get_active_popups_cached();
    
    if (empty($popups)) {
        return new WP_REST_Response(array(), 200);
    }
    
    $data = array();
    
    foreach ($popups as $popup) {
        $popup_data = starter_process_popup_for_api($popup, $user_id);
        
        if ($popup_data !== null) {
            $data[] = $popup_data;
        }
    }
    
    // Ordenar por prioridad (menor número = mayor prioridad)
    usort($data, function($a, $b) {
        return $a['priority'] - $b['priority'];
    });
    
    return new WP_REST_Response($data, 200);
}

/**
 * Callback para obtener popup por tipo
 */
function starter_get_popup_by_type($request) {
    $type = $request->get_param('type');
    $user_id = get_current_user_id();
    
    // Validar tipo
    $valid_types = array_keys(starter_get_popup_types());
    if (!in_array($type, $valid_types)) {
        return new WP_Error('invalid_type', 'Tipo de popup no válido', array('status' => 400));
    }
    
    // Usar caché de popups activos y filtrar por tipo
    $all_popups = starter_get_active_popups_cached();
    $popup = null;
    
    foreach ($all_popups as $p) {
        $popup_type = get_post_meta($p->ID, '_popup_type', true);
        if ($popup_type === $type) {
            $popup = $p;
            break;
        }
    }
    
    if (!$popup) {
        return new WP_REST_Response(null, 200);
    }
    
    $popup_data = starter_process_popup_for_api($popup, $user_id);
    
    return new WP_REST_Response($popup_data, 200);
}

/**
 * Callback para registrar interacción con popup
 * Permite interacciones anónimas para login_prompt
 */
function starter_popup_interaction($request) {
    $popup_id = intval($request->get_param('id'));
    $user_id = get_current_user_id();
    $action = $request->get_param('action'); // 'viewed', 'dismissed', 'clicked'
    
    if (!$popup_id) {
        return new WP_Error('invalid_request', 'Solicitud inválida', array('status' => 400));
    }
    
    // Verificar que el popup existe
    $popup = get_post($popup_id);
    if (!$popup || $popup->post_type !== 'starter_popup') {
        return new WP_Error('popup_not_found', 'Popup no encontrado', array('status' => 404));
    }
    
    $popup_type = get_post_meta($popup_id, '_popup_type', true);
    
    // Para login_prompt, permitir interacción anónima (solo tracking básico)
    if ($popup_type === 'login_prompt') {
        // No requiere user_id, solo registrar que hubo interacción
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Interacción registrada',
        ), 200);
    }
    
    // Para otros tipos, requerir usuario autenticado
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Debes iniciar sesión', array('status' => 401));
    }
    
    // Registrar interacción según el tipo
    switch ($popup_type) {
        case 'membership_expiration':
            if ($action === 'viewed' || $action === 'dismissed') {
                starter_mark_expiration_popup_shown($user_id);
            }
            break;
            
        case 'membership_expired':
            if ($action === 'viewed' || $action === 'dismissed') {
                starter_mark_expired_popup_shown($user_id);
            }
            break;
            
        case 'referral_bonus':
            if ($action === 'viewed' || $action === 'dismissed') {
                starter_mark_referral_bonus_notified($user_id);
            }
            break;
    }
    
    // Registrar en meta del usuario para tracking general
    $interactions = get_user_meta($user_id, '_starter_popup_interactions', true);
    if (!is_array($interactions)) {
        $interactions = array();
    }
    
    $interactions[$popup_id] = array(
        'action' => $action,
        'timestamp' => current_time('mysql'),
    );
    
    update_user_meta($user_id, '_starter_popup_interactions', $interactions);
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Interacción registrada',
    ), 200);
}

/**
 * Callback para responder a la oferta de membresía por antigüedad
 * 
 * NOTA: Este endpoint ahora SOLO acepta 'accepted' como respuesta válida.
 * El popup de membresía por antigüedad es OBLIGATORIO.
 */
function starter_legacy_membership_respond($request) {
    $user_id = get_current_user_id();
    $response = $request->get_param('response');
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Debes iniciar sesión', array('status' => 401));
    }
    
    // Solo se acepta 'accepted' - el popup es obligatorio
    if ($response !== 'accepted') {
        return new WP_Error('invalid_response', 'Debes aceptar los términos para continuar', array('status' => 400));
    }
    
    // Verificar elegibilidad
    if (!starter_user_eligible_for_legacy_membership($user_id)) {
        return new WP_Error('not_eligible', 'No eres elegible para esta membresía', array('status' => 403));
    }
    
    // Registrar respuesta
    $result = starter_register_legacy_membership_response($user_id, 'accepted');
    
    if (!$result) {
        return new WP_Error('registration_failed', 'Error al procesar la respuesta', array('status' => 500));
    }
    
    // Devolver info de la membresía
    $membership_data = null;
    if (function_exists('starter_get_user_membership_info')) {
        $membership_data = starter_get_user_membership_info($user_id);
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => '¡Felicidades! Tu membresía por antigüedad ha sido activada.',
        'response' => 'accepted',
        'membership' => $membership_data,
    ), 200);
}
