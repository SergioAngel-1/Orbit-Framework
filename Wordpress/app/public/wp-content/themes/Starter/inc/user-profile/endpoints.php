<?php
/**
 * Endpoints para el perfil de usuario
 * 
 * Este archivo contiene las funciones relacionadas con los endpoints
 * para obtener y actualizar el perfil de usuario.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoint para actualizar perfil de usuario
 */
function register_user_profile_endpoints() {
    // Endpoint para obtener perfil con selección de campos (GET)
    register_rest_route('starter/v1', '/user/profile', array(
        'methods' => 'GET',
        'callback' => 'get_user_profile_callback',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'args' => array(
            'fields' => array(
                'default' => 'all',
                'description' => 'Campos específicos a devolver (separados por comas)'
            )
        )
    ));

    // Endpoint para actualizar perfil (POST)
    register_rest_route('starter/v1', '/user/profile', array(
        'methods' => 'POST',
        'callback' => 'update_user_profile_callback',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ));
}
add_action('rest_api_init', 'register_user_profile_endpoints');

/**
 * Callback para obtener perfil de usuario con soporte para campos específicos
 * Implementa una versión "GraphQL-like" para solo devolver los campos solicitados
 */
function get_user_profile_callback($request) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Usuario no autenticado', array('status' => 401));
    }
    
    // Procesar campos solicitados
    $fields_param = $request->get_param('fields');
    $fields = $fields_param === 'all' ? null : explode(',', $fields_param);
    
    $user = get_userdata($user_id);
    
    // Sincronizar estado de newsletter con la lista de suscriptores
    $existing_subscribers = get_option('starter_newsletter_subscribers', array());
    $is_subscribed_in_list = in_array($user->user_email, $existing_subscribers);
    $newsletter_meta = (bool) get_user_meta($user_id, 'newsletter', true);
    
    // Si hay discrepancia, actualizar user_meta con el estado de la lista
    if ($is_subscribed_in_list !== $newsletter_meta) {
        update_user_meta($user_id, 'newsletter', $is_subscribed_in_list);
        $newsletter_meta = $is_subscribed_in_list;
    }
    
    // Construir respuesta con todos los campos posibles
    $full_profile = array(
        'id' => $user->ID,
        'firstName' => $user->first_name,
        'lastName' => $user->last_name,
        'displayName' => $user->display_name,
        'email' => $user->user_email,
        'username' => $user->user_login,
        'phone' => get_user_meta($user_id, 'phone', true),
        'birthDate' => get_user_meta($user_id, 'birth_date', true),
        'gender' => get_user_meta($user_id, 'gender', true),
        'newsletter' => $newsletter_meta,
        'active' => (bool) get_user_meta($user_id, 'active', true),
        'documentId' => get_user_meta($user_id, 'cedula', true),
        'acceptedTerms' => (bool) get_user_meta($user_id, 'accepted_terms', true),
        'acceptedTermsDate' => get_user_meta($user_id, 'accepted_terms_date', true) ?: null,
        'acceptedDataVeracity' => (bool) get_user_meta($user_id, 'accepted_data_veracity', true),
        'acceptedDataVeracityDate' => get_user_meta($user_id, 'accepted_data_veracity_date', true) ?: null,
        'avatar' => function_exists('starter_get_custom_avatar_url') ? starter_get_custom_avatar_url($user_id) : '',
    );
    
    // Si se solicitan campos específicos, filtrar la respuesta
    if ($fields !== null) {
        $filtered_profile = array();
        
        foreach ($fields as $field) {
            $field = trim($field);
            if (isset($full_profile[$field])) {
                $filtered_profile[$field] = $full_profile[$field];
            }
        }
        
        // Siempre incluir ID para referencia
        if (!isset($filtered_profile['id'])) {
            $filtered_profile['id'] = $user_id;
        }
        
        $response_data = $filtered_profile;
    } else {
        $response_data = $full_profile;
    }
    
    return rest_ensure_response($response_data);
}

/**
 * Callback para actualizar el perfil del usuario
 */
function update_user_profile_callback($request) {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Usuario no autenticado', array('status' => 401));
    }
    
    $params = $request->get_params();
    $response = array('success' => false);
    
    // Log para depuración
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Parámetros recibidos en update_user_profile_callback: ' . print_r($params, true));
    }
    
    // Datos básicos del usuario para wp_update_user
    $userdata = array(
        'ID' => $user_id
    );
    
    // Actualizar nombre y apellido en los campos estándar de WordPress
    if (isset($params['firstName'])) {
        $userdata['first_name'] = sanitize_text_field($params['firstName']);
    }
    
    if (isset($params['lastName'])) {
        $userdata['last_name'] = sanitize_text_field($params['lastName']);
    }
    
    // Actualizar email si se proporciona y es diferente al actual
    $current_user = get_userdata($user_id);
    $current_email = $current_user->user_email;
    
    if (isset($params['email']) && is_email($params['email']) && $params['email'] !== $current_email) {
        $new_email = sanitize_email($params['email']);
        
        // Log para depuración
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Intentando actualizar email de: ' . $current_email . ' a: ' . $new_email);
        }
        
        // Verificar si el correo ya está en uso por otro usuario
        if (email_exists($new_email) && email_exists($new_email) !== $user_id) {
            return new WP_Error('email_exists', 'El correo electrónico ya está en uso por otro usuario.', array('status' => 400));
        }
        
        // Guardar el nuevo correo en los datos del usuario para actualizarlo
        $userdata['user_email'] = $new_email;
        
        // Configurar para enviar correo de verificación (si es necesario)
        // Esto es manejado internamente por WordPress
        do_action('send_email_change_email', $user_id, $new_email);
        
        // Registrar que se está cambiando el correo
        update_user_meta($user_id, 'email_change_pending', true);
        update_user_meta($user_id, 'email_change_new', $new_email);
    }
    
    // Actualizar los datos básicos del usuario
    $user_id = wp_update_user($userdata);
    
    if (is_wp_error($user_id)) {
        error_log('Error al actualizar usuario: ' . $user_id->get_error_message());
        return new WP_Error('update_failed', $user_id->get_error_message(), array('status' => 500));
    }
    
    // Actualizar campos personalizados
    if (isset($params['phone'])) {
        update_user_meta($user_id, 'phone', sanitize_text_field($params['phone']));
    }
    
    if (isset($params['birthDate'])) {
        update_user_meta($user_id, 'birth_date', sanitize_text_field($params['birthDate']));
    }
    
    if (isset($params['gender'])) {
        update_user_meta($user_id, 'gender', sanitize_text_field($params['gender']));
    }
    
    if (isset($params['newsletter'])) {
        $newsletter_value = (bool) $params['newsletter'];
        update_user_meta($user_id, 'newsletter', $newsletter_value);
        
        // Sincronizar con la lista de suscriptores del newsletter
        $user_email = $current_user->user_email;
        $existing_subscribers = get_option('starter_newsletter_subscribers', array());
        
        if ($newsletter_value) {
            // Usuario se suscribe: agregar a la lista si no está
            if (!in_array($user_email, $existing_subscribers)) {
                $existing_subscribers[] = $user_email;
                update_option('starter_newsletter_subscribers', $existing_subscribers);
                
                // Registrar la fecha de suscripción
                $subscription_dates = get_option('starter_newsletter_subscription_dates', array());
                $subscription_dates[$user_email] = current_time('mysql');
                update_option('starter_newsletter_subscription_dates', $subscription_dates);
            }
        } else {
            // Usuario se desuscribe: eliminar de la lista si está
            $key = array_search($user_email, $existing_subscribers);
            if ($key !== false) {
                unset($existing_subscribers[$key]);
                $existing_subscribers = array_values($existing_subscribers); // Reindexar
                update_option('starter_newsletter_subscribers', $existing_subscribers);
                
                // Eliminar la fecha de suscripción
                $subscription_dates = get_option('starter_newsletter_subscription_dates', array());
                if (isset($subscription_dates[$user_email])) {
                    unset($subscription_dates[$user_email]);
                    update_option('starter_newsletter_subscription_dates', $subscription_dates);
                }
            }
        }
    }
    
    if (isset($params['active']) && current_user_can('manage_options')) {
        update_user_meta($user_id, 'active', (bool) $params['active']);
    }
    
    // Guardar cédula solo si no tiene una guardada previamente (única vez)
    if (isset($params['documentId']) && !empty($params['documentId'])) {
        $existing_cedula = get_user_meta($user_id, 'cedula', true);
        if (empty($existing_cedula)) {
            $cedula = sanitize_text_field($params['documentId']);
            update_user_meta($user_id, 'cedula', $cedula);
            update_user_meta($user_id, 'billing_cedula', $cedula);
        }
    }
    
    // Guardar aceptación de veracidad de datos (solo si no fue aceptada previamente)
    if (isset($params['acceptedDataVeracity']) && $params['acceptedDataVeracity']) {
        $already_accepted = (bool) get_user_meta($user_id, 'accepted_data_veracity', true);
        if (!$already_accepted) {
            update_user_meta($user_id, 'accepted_data_veracity', true);
            update_user_meta($user_id, 'accepted_data_veracity_date', current_time('mysql'));
        }
    }
    
    // Guardar aceptación de términos y condiciones (solo si no fueron aceptados previamente)
    if (isset($params['acceptedTerms']) && $params['acceptedTerms']) {
        $already_accepted = (bool) get_user_meta($user_id, 'accepted_terms', true);
        if (!$already_accepted) {
            update_user_meta($user_id, 'accepted_terms', true);
            update_user_meta($user_id, 'accepted_terms_date', current_time('mysql'));
        }
    }
    
    // Obtener los datos actualizados del usuario para devolverlos en la respuesta
    $user = get_userdata($user_id);
    
    // Verificar que el email se haya actualizado correctamente
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Email después de la actualización: ' . $user->user_email);
    }
    
    // Verificar si hay un cambio de correo pendiente
    $email_change_pending = get_user_meta($user_id, 'email_change_pending', true);
    $new_email = get_user_meta($user_id, 'email_change_new', true);
    
    // Preparar datos para la respuesta
    $user_data = array(
        'id' => $user->ID,
        'firstName' => $user->first_name,
        'lastName' => $user->last_name,
        'email' => $user->user_email, // Usar user_email directamente
        'phone' => get_user_meta($user_id, 'phone', true),
        'birthDate' => get_user_meta($user_id, 'birth_date', true),
        'gender' => get_user_meta($user_id, 'gender', true),
        'newsletter' => (bool) get_user_meta($user_id, 'newsletter', true),
        'active' => (bool) get_user_meta($user_id, 'active', true),
        'emailChangePending' => (bool) $email_change_pending,
        'newEmail' => $new_email ?: null,
        'documentId' => get_user_meta($user_id, 'cedula', true),
        'acceptedTerms' => (bool) get_user_meta($user_id, 'accepted_terms', true),
        'acceptedTermsDate' => get_user_meta($user_id, 'accepted_terms_date', true) ?: null,
        'acceptedDataVeracity' => (bool) get_user_meta($user_id, 'accepted_data_veracity', true),
        'acceptedDataVeracityDate' => get_user_meta($user_id, 'accepted_data_veracity_date', true) ?: null,
        'avatar' => function_exists('starter_get_custom_avatar_url') ? starter_get_custom_avatar_url($user_id) : '',
    );
    
    // Mensaje personalizado si hay un cambio de correo pendiente
    $message = 'Perfil actualizado correctamente';
    if ($email_change_pending && $new_email) {
        $message = 'Perfil actualizado. Se ha enviado un correo de verificación a ' . $new_email . ' para confirmar el cambio de dirección de correo electrónico.';
    }
    
    $response = array(
        'success' => true,
        'message' => $message,
        'user' => $user_data
    );
    
    return rest_ensure_response($response);
}
