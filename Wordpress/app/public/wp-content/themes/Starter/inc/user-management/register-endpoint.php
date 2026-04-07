<?php
/**
 * Registro de usuarios - Endpoint personalizado
 * 
 * Este archivo contiene las funciones relacionadas con el registro de nuevos usuarios
 * y la configuración de los endpoints correspondientes.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registro de usuarios - Endpoint personalizado
 * Añade un endpoint para el registro de nuevos usuarios con estado pendiente
 */
function custom_user_register_endpoint() {
    // Endpoint original (mantener por compatibilidad)
    register_rest_route('wp/v2', '/users/register', array(
        'methods' => 'POST',
        'callback' => 'custom_register_user',
        'permission_callback' => function() {
            return true; // Permitir acceso público
        }
    ));
    
    // Nuevo endpoint para el frontend React
    register_rest_route('starter/v1', '/register', array(
        'methods' => 'POST',
        'callback' => 'custom_register_user',
        'permission_callback' => function() {
            return true; // Permitir acceso público
        }
    ));
}
add_action('rest_api_init', 'custom_user_register_endpoint', 20);

/**
 * Función para registrar un nuevo usuario con validación
 * Soporta aprobación automática configurable desde el admin
 */
function custom_register_user($request) {
    $username = sanitize_user($request['username']);
    $email = sanitize_email($request['email']);
    $password = $request['password'];
    $name = isset($request['name']) ? sanitize_text_field($request['name']) : $username;
    $phone = isset($request['phone']) ? sanitize_text_field($request['phone']) : '';
    $cedula = isset($request['cedula']) ? sanitize_text_field($request['cedula']) : '';
    $birth_date = isset($request['birth_date']) ? sanitize_text_field($request['birth_date']) : '';
    
    // Campos de aceptación legal
    $accepted_terms = isset($request['accepted_terms']) ? (bool) $request['accepted_terms'] : false;
    $accepted_data_veracity = isset($request['accepted_data_veracity']) ? (bool) $request['accepted_data_veracity'] : false;
    
    // Corregir el manejo del código de referido (puede venir como referral_code o referralCode)
    $referral_code = '';
    if (isset($request['referral_code']) && !empty($request['referral_code'])) {
        $referral_code = sanitize_text_field($request['referral_code']);
    } elseif (isset($request['referralCode']) && !empty($request['referralCode'])) {
        $referral_code = sanitize_text_field($request['referralCode']);
    }
    
    // Verificar configuración de aprobación automática
    $auto_approval_enabled = function_exists('starter_is_auto_approval_enabled') 
        ? starter_is_auto_approval_enabled() 
        : false;
    
    // Registrar para depuración
    error_log(sprintf(
        "Datos de registro recibidos - Usuario: %s, Código de referido: %s, Aprobación automática: %s",
        $username, $referral_code, $auto_approval_enabled ? 'SÍ' : 'NO'
    ));

    // Validar campos
    if (empty($username) || empty($email) || empty($password)) {
        return new WP_Error('missing_fields', 'Todos los campos son obligatorios', array('status' => 400));
    }

    // Validar email
    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'El email no es válido', array('status' => 400));
    }
    
    // Verificar si el dominio de email está bloqueado
    if (function_exists('starter_is_email_domain_blocked') && starter_is_email_domain_blocked($email)) {
        return new WP_Error('blocked_domain', 'El dominio de correo electrónico no está permitido', array('status' => 400));
    }

    // Verificar si el nombre de usuario ya existe
    if (username_exists($username)) {
        return new WP_Error('username_exists', 'El nombre de usuario ya está registrado', array('status' => 400));
    }
    
    // Verificar si el correo electrónico ya existe
    if (email_exists($email)) {
        return new WP_Error('email_exists', 'El correo electrónico ya está registrado', array('status' => 400));
    }
    
    // Verificar si el teléfono ya está registrado (si se proporciona)
    if (!empty($phone)) {
        global $wpdb;
        $normalized_phone = preg_replace('/[^0-9+]/', '', $phone);
        $existing_phone = $wpdb->get_var($wpdb->prepare("
            SELECT user_id FROM {$wpdb->usermeta} 
            WHERE (meta_key = 'phone' OR meta_key = 'billing_phone')
            AND (meta_value = %s OR REPLACE(REPLACE(meta_value, ' ', ''), '-', '') = %s)
            LIMIT 1
        ", $phone, $normalized_phone));
        
        if ($existing_phone) {
            return new WP_Error('phone_exists', 'Este número de teléfono ya está registrado en otra cuenta', array('status' => 400));
        }
    }
    
    // Verificar si la cédula ya está registrada (si se proporciona)
    if (!empty($cedula)) {
        global $wpdb;
        $normalized_cedula = preg_replace('/[^0-9]/', '', $cedula);
        $existing_cedula = $wpdb->get_var($wpdb->prepare("
            SELECT user_id FROM {$wpdb->usermeta} 
            WHERE (meta_key = 'cedula' OR meta_key = 'billing_cedula' OR meta_key = 'documento_identidad')
            AND (meta_value = %s OR REPLACE(REPLACE(REPLACE(meta_value, '.', ''), '-', ''), ' ', '') = %s)
            LIMIT 1
        ", $cedula, $normalized_cedula));
        
        if ($existing_cedula) {
            return new WP_Error('cedula_exists', 'Esta cédula ya está registrada en otra cuenta', array('status' => 400));
        }
    }

    // Si hay código de referido, guardarlo en cookie para que el sistema de referidos lo procese
    if (!empty($referral_code)) {
        setcookie('starter_referral', $referral_code, time() + (86400 * 30), '/');
        error_log("Código de referido recibido durante registro: $referral_code");
        
        // Guardarlo en $_POST con el nombre correcto para que el hook user_register lo procese
        $_POST['referral_code'] = $referral_code;
        
        // También guardarlo con el nombre alternativo por si acaso
        $_POST['referralCode'] = $referral_code;
        
        error_log("Código de referido guardado en _POST['referral_code']: {$_POST['referral_code']}");
    }

    // Determinar el rol inicial según configuración de aprobación
    $initial_role = $auto_approval_enabled ? 'customer' : 'subscriber';

    // Crear el usuario
    $user_data = array(
        'user_login' => $username,
        'user_email' => $email,
        'user_pass' => $password,
        'display_name' => $name,
        'first_name' => $name,
        'nickname' => $name,
        'role' => $initial_role
    );

    $user_id = wp_insert_user($user_data);

    if (is_wp_error($user_id)) {
        return $user_id;
    }

    // Guardar el teléfono si está disponible
    if (!empty($phone)) {
        update_user_meta($user_id, 'phone', $phone);
        update_user_meta($user_id, 'billing_phone', $phone);
    }
    
    // Guardar la cédula si está disponible
    if (!empty($cedula)) {
        update_user_meta($user_id, 'cedula', $cedula);
        update_user_meta($user_id, 'billing_cedula', $cedula);
    }
    
    // Guardar la fecha de nacimiento si está disponible
    if (!empty($birth_date)) {
        update_user_meta($user_id, 'birth_date', $birth_date);
    }
    
    // Guardar aceptación de términos y veracidad de datos
    $registration_date = current_time('mysql');
    if ($accepted_terms) {
        update_user_meta($user_id, 'accepted_terms', '1');
        update_user_meta($user_id, 'accepted_terms_date', $registration_date);
    }
    if ($accepted_data_veracity) {
        update_user_meta($user_id, 'accepted_data_veracity', '1');
        update_user_meta($user_id, 'accepted_data_veracity_date', $registration_date);
    }
    
    // IMPORTANTE: Setear el estado de aprobación ANTES de procesar referidos
    // para que starter_memberships_grant_referral_membership sepa si debe
    // asignar la membresía inmediatamente o guardarla como pendiente
    if (!$auto_approval_enabled) {
        update_user_meta($user_id, 'pending_approval', true);
    }
    
    // NOTA: No llamar manualmente a starter_rp_generate_referral_code ni
    // starter_rp_process_referral_relationship aquí. wp_insert_user() ya dispara
    // el hook 'user_register' que ejecuta ambas funciones automáticamente
    // (ver referral-init.php prioridades 10 y 20).

    // Lógica diferenciada según tipo de aprobación
    if ($auto_approval_enabled) {
        // APROBACIÓN AUTOMÁTICA: Usuario aprobado inmediatamente
        error_log("Starter: Usuario $user_id aprobado automáticamente");
        
        // Marcar como previamente aprobado (para sistema de puntos)
        update_user_meta($user_id, '_user_previously_approved', '1');
        
        // Disparar acción de primera aprobación (para puntos de referido y membresía)
        do_action('starter_user_first_approval', $user_id);
        
        // Enviar email de bienvenida si está configurado
        $send_welcome = get_option('starter_send_welcome_email', '1') === '1';
        if ($send_welcome && function_exists('starter_send_account_approved_email')) {
            starter_send_account_approved_email($user_id);
        }
        
        $response_message = '¡Registro exitoso! Ya puedes iniciar sesión con tu cuenta.';
        $response_status = 'approved';
        
    } else {
        // APROBACIÓN MANUAL: Usuario ya está marcado como pendiente (arriba)
        
        // Enviar notificación al administrador
        if (function_exists('send_admin_notification_new_user')) {
            send_admin_notification_new_user($user_id, $username, $email);
        }
        
        // Enviar correo de notificación de registro en revisión al usuario
        if (function_exists('starter_send_welcome_email')) {
            $notification_sent = starter_send_welcome_email($user_id, $username, $email, $referral_code);
            if (!$notification_sent) {
                error_log("Advertencia: No se pudo enviar el correo de registro en revisión al usuario ID: $user_id");
            }
        }
        
        $response_message = 'Usuario registrado correctamente. Un administrador revisará tu cuenta pronto.';
        $response_status = 'pending';
    }

    return array(
        'status' => 'success',
        'approval_status' => $response_status,
        'message' => $response_message,
        'user_id' => $user_id,
        'auto_approved' => $auto_approval_enabled
    );
}

/**
 * Modificar respuesta de user/me para incluir si está pendiente de aprobación
 */
function custom_prepare_user_response($response, $user, $request) {
    if (is_object($response) && $request->get_route() === '/wp/v2/users/me') {
        $pending = get_user_meta($user->ID, 'pending_approval', true);
        $data = $response->get_data();
        $data['pending'] = !empty($pending);
        $response->set_data($data);
    }
    return $response;
}
add_filter('rest_prepare_user', 'custom_prepare_user_response', 10, 3);
