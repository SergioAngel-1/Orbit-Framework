<?php
/**
 * Funciones relacionadas con el perfil de usuario
 * 
 * Este archivo contiene funciones personalizadas para gestionar perfiles de usuario
 */

// Registrar endpoints de API REST para el perfil de usuario
function register_profile_endpoints() {
    register_rest_route('starter/v1', '/profile', array(
        'methods' => 'GET',
        'callback' => 'get_user_profile',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
    
    register_rest_route('starter/v1', '/profile', array(
        'methods' => 'POST',
        'callback' => 'update_user_profile',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
}

// Obtener datos del perfil del usuario actual
function get_user_profile($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Usuario no autenticado', array('status' => 401));
    }
    
    $user = get_userdata($user_id);
    
    if (!$user) {
        return new WP_Error('user_not_found', 'Usuario no encontrado', array('status' => 404));
    }
    
    // Obtener meta datos adicionales del usuario
    $phone = get_user_meta($user_id, 'phone', true);
    $document_type = get_user_meta($user_id, 'document_type', true);
    $document_number = get_user_meta($user_id, 'document_number', true);
    
    $profile_data = array(
        'id' => $user_id,
        'username' => $user->user_login,
        'email' => $user->user_email,
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'phone' => $phone,
        'document_type' => $document_type,
        'document_number' => $document_number,
    );
    
    return $profile_data;
}

// Actualizar datos del perfil del usuario
function update_user_profile($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Usuario no autenticado', array('status' => 401));
    }
    
    $params = $request->get_params();
    
    // Datos básicos del usuario
    $userdata = array(
        'ID' => $user_id
    );
    
    // Actualizar email si se proporciona
    if (isset($params['email']) && is_email($params['email'])) {
        $userdata['user_email'] = sanitize_email($params['email']);
    }
    
    // Actualizar nombre y apellido si se proporcionan
    if (isset($params['first_name'])) {
        $userdata['first_name'] = sanitize_text_field($params['first_name']);
    }
    
    if (isset($params['last_name'])) {
        $userdata['last_name'] = sanitize_text_field($params['last_name']);
    }
    
    // Actualizar datos básicos del usuario
    if (count($userdata) > 1) {
        $user_id = wp_update_user($userdata);
        
        if (is_wp_error($user_id)) {
            return new WP_Error('update_failed', $user_id->get_error_message(), array('status' => 400));
        }
    }
    
    // Actualizar meta datos adicionales
    if (isset($params['phone'])) {
        update_user_meta($user_id, 'phone', sanitize_text_field($params['phone']));
    }
    
    if (isset($params['document_type'])) {
        update_user_meta($user_id, 'document_type', sanitize_text_field($params['document_type']));
    }
    
    if (isset($params['document_number'])) {
        update_user_meta($user_id, 'document_number', sanitize_text_field($params['document_number']));
    }
    
    // Devolver datos actualizados
    return get_user_profile($request);
}
