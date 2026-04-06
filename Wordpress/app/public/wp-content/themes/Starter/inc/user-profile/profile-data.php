<?php
/**
 * Datos del perfil de usuario
 * 
 * Este archivo contiene las funciones relacionadas con la obtención y
 * manipulación de los datos del perfil de usuario.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añadir campos de perfil personalizados a la respuesta de la API REST
 */
function add_profile_fields_to_user_response($response, $user, $request) {
    if (!empty($user)) {
        $user_id = $user->ID;
        
        // Preparar campos a devolver
        $data = array(
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'email' => $user->user_email,
            'phone' => get_user_meta($user_id, 'phone', true),
            'birthDate' => get_user_meta($user_id, 'birth_date', true),
            'gender' => get_user_meta($user_id, 'gender', true),
            'newsletter' => (bool) get_user_meta($user_id, 'newsletter', true),
            'active' => (bool) get_user_meta($user_id, 'active', true),
            'document_id' => get_user_meta($user_id, 'cedula', true),
            'accepted_terms' => (bool) get_user_meta($user_id, 'accepted_terms', true),
            'accepted_terms_date' => get_user_meta($user_id, 'accepted_terms_date', true) ?: null,
            'accepted_data_veracity' => (bool) get_user_meta($user_id, 'accepted_data_veracity', true),
            'accepted_data_veracity_date' => get_user_meta($user_id, 'accepted_data_veracity_date', true) ?: null,
        );
        
        // Añadir campos a la respuesta
        foreach ($data as $field => $value) {
            $response->data[$field] = $value;
        }
    }
    
    return $response;
}
add_filter('rest_prepare_user', 'add_profile_fields_to_user_response', 10, 3);
