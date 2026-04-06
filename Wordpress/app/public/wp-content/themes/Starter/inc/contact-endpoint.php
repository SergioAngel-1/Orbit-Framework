<?php
/**
 * Endpoint para formularios de contacto
 * 
 * Este archivo maneja el envío de formularios de contacto a través de la API REST
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar endpoint para formularios de contacto
 */
add_action('rest_api_init', function () {
    register_rest_route('starter/v1', '/contact', array(
        'methods' => 'POST',
        'callback' => 'starter_handle_contact_form',
        'permission_callback' => '__return_true', // Permitir a cualquiera enviar formularios de contacto
        'args' => array(
            'name' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'email' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
            ),
            'phone' => array(
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'subject' => array(
                'required' => false,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'message' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            )
        )
    ));
});

/**
 * Manejar el envío del formulario de contacto
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function starter_handle_contact_form($request) {
    try {
        $name = $request->get_param('name');
        $email = $request->get_param('email');
        $phone = $request->get_param('phone');
        $subject = $request->get_param('subject');
        $message = $request->get_param('message');
        
        if (empty($name) || empty($email) || empty($message)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Faltan campos obligatorios'
            ), 400);
        }
        
        if (!is_email($email)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Email inválido'
            ), 400);
        }
        
        $current_user = wp_get_current_user();
        $is_authenticated = $current_user->ID > 0;
        
        $form_data = array(
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'subject' => $subject,
            'message' => $message,
            'is_authenticated' => $is_authenticated,
            'user_id' => $is_authenticated ? $current_user->ID : null,
            'timestamp' => current_time('mysql'),
            'ip_address' => function_exists('starter_get_client_ip') ? starter_get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        );
        
        $admin_email_sent = starter_send_contact_admin_notification($form_data);
        $user_email_sent = starter_send_contact_user_confirmation($form_data);
        
        if ($admin_email_sent && $user_email_sent) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Mensaje enviado correctamente'
            ), 200);
        } else {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => 'Mensaje recibido correctamente'
            ), 200);
        }
        
    } catch (Exception $e) {
        error_log("Error en formulario de contacto: " . $e->getMessage());
        
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Error al procesar el mensaje'
        ), 500);
    }
}

/**
 * Enviar notificación al administrador sobre nuevo formulario de contacto
 * 
 * @param array $form_data Datos del formulario
 * @return bool True si el correo se envió correctamente
 */
function starter_send_contact_admin_notification($form_data) {
    $admin_email = get_option('admin_email');
    $b = function_exists('site_get_email_branding') ? site_get_email_branding() : starter_get_branding();
    
    $subject = 'Nuevo mensaje de contacto - ' . ($form_data['subject'] ?: 'Sin asunto') . ' | ' . $b['site_name'];
    
    // Formatear el tipo de asunto
    $subject_types = array(
        'consulta' => 'Consulta general',
        'pedido' => 'Información sobre pedido',
        'productos' => 'Información sobre productos',
        'privacidad' => 'Privacidad y datos personales',
        'otro' => 'Otro asunto'
    );
    
    $subject_display = $subject_types[$form_data['subject']] ?? ($form_data['subject'] ?: 'Sin especificar');
    
    $priority_html = '';
    if ($form_data['is_authenticated']) {
        $priority_html = '
            <div style="background-color: #FFF3CD; border-left: 4px solid #FFC107; padding: 15px; margin: 20px 0; border-radius: 4px; color: #856404;">
                <h3 style="margin-top: 0; color: #856404;">⭐ Usuario autenticado</h3>
                <p style="margin-bottom: 0;">Este mensaje proviene de un usuario registrado en la plataforma. Priorizar respuesta.</p>
            </div>';
    }
    
    $body = '
        <h3 style="color: ' . esc_attr($b['primary_color']) . ';">📧 Detalles del contacto:</h3>
        
        <div class="highlight-box">
            <p style="margin: 5px 0;"><strong>Nombre:</strong> ' . esc_html($form_data['name']) . '</p>
            <p style="margin: 5px 0;"><strong>Email:</strong> ' . esc_html($form_data['email']) . '</p>
            <p style="margin: 5px 0;"><strong>Teléfono:</strong> ' . esc_html($form_data['phone'] ?: 'No proporcionado') . '</p>
            <p style="margin: 5px 0;"><strong>Asunto:</strong> ' . esc_html($subject_display) . '</p>
            <p style="margin: 5px 0;"><strong>Fecha:</strong> ' . date('d/m/Y H:i', strtotime($form_data['timestamp'])) . '</p>
            <p style="margin: 5px 0;"><strong>Usuario autenticado:</strong> ' . ($form_data['is_authenticated'] ? 'Sí (ID: ' . $form_data['user_id'] . ')' : 'No') . '</p>
            <p style="margin: 5px 0;"><strong>IP:</strong> ' . esc_html($form_data['ip_address']) . '</p>
        </div>
        
        ' . $priority_html . '
        
        <h3 style="color: ' . esc_attr($b['primary_color']) . ';">💬 Mensaje:</h3>
        <div style="background-color: #E7F3FF; border: 1px solid #B3D9FF; padding: 20px; margin: 20px 0; border-radius: 8px;">
            <p style="margin: 0; white-space: pre-wrap; font-size: 15px; line-height: 1.6;">' . esc_html($form_data['message']) . '</p>
        </div>
    ';

    $footer = '<strong>Sistema de contacto automático</strong><br>' . esc_html($b['site_name']);
    $message = starter_email_wrap($b, 'Nuevo mensaje de contacto recibido', $body, $footer);
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mail_sent = wp_mail($admin_email, $subject, $message, $headers);
    
    if ($mail_sent) {
        error_log("Notificación de contacto enviada al administrador: $admin_email (De: " . $form_data['email'] . ")");
    } else {
        error_log("Error al enviar notificación de contacto al administrador: $admin_email (De: " . $form_data['email'] . ")");
    }
    return $mail_sent;
}

/**
 * Enviar confirmación al usuario sobre su mensaje de contacto
 * 
 * @param array $form_data Datos del formulario
 * @return bool True si el correo se envió correctamente
 */
function starter_send_contact_user_confirmation($form_data) {
    $b = function_exists('site_get_email_branding') ? site_get_email_branding() : starter_get_branding();
    
    $subject = 'Hemos recibido tu mensaje | ' . $b['site_name'];
    
    $subject_types = array(
        'consulta' => 'Consulta general',
        'pedido' => 'Información sobre mi pedido',
        'productos' => 'Información sobre productos',
        'privacidad' => 'Privacidad y datos personales',
        'otro' => 'Otro asunto'
    );
    
    $subject_display = $subject_types[$form_data['subject']] ?? ($form_data['subject'] ?: 'Consulta general');
    
    $auth_html = '';
    if ($form_data['is_authenticated']) {
        $auth_html = '
            <div class="success-box">
                <h3 style="margin-top: 0;">👤 Usuario registrado</h3>
                <p style="margin-bottom: 0;">Como usuario registrado, tu consulta tendrá prioridad y podremos brindarte un soporte más personalizado.</p>
            </div>';
    }
    
    $contact_phone = function_exists('site_get_option') ? site_get_option('contact_phone', '') : '';
    $phone_html = !empty($contact_phone) 
        ? '<li>Si es urgente, puedes contactarnos directamente al <strong>' . esc_html($contact_phone) . '</strong></li>' 
        : '';
    
    $body = '
        <p>¡Hola <strong>' . esc_html($form_data['name']) . '</strong>!</p>
        
        <div class="success-box">
            <h3 style="margin-top: 0;">✅ ¡Mensaje recibido!</h3>
            <p style="margin-bottom: 0;">Hemos recibido tu mensaje correctamente y nuestro equipo lo revisará a la brevedad.</p>
        </div>
        
        <div class="info-box">
            <h3 style="margin-top: 0; color: #004085;">⏰ ¿Qué sigue ahora?</h3>
            <ul style="margin-bottom: 0; padding-left: 20px;">
                <li>Nuestro equipo revisará tu mensaje dentro de las próximas <strong>24-48 horas</strong></li>
                <li>Te contactaremos por correo electrónico o teléfono según tu consulta</li>
                ' . $phone_html . '
            </ul>
        </div>
        
        <h3 style="color: ' . esc_attr($b['primary_color']) . ';">📋 Resumen de tu mensaje:</h3>
        <div class="highlight-box">
            <p style="margin: 5px 0;"><strong>Fecha de envío:</strong> ' . date('d/m/Y H:i', strtotime($form_data['timestamp'])) . '</p>
            <p style="margin: 5px 0;"><strong>Asunto:</strong> ' . esc_html($subject_display) . '</p>
            <p style="margin: 5px 0;"><strong>Email de contacto:</strong> ' . esc_html($form_data['email']) . '</p>
            ' . (!empty($form_data['phone']) ? '<p style="margin: 5px 0;"><strong>Teléfono:</strong> ' . esc_html($form_data['phone']) . '</p>' : '') . '
        </div>
        
        ' . $auth_html . '
        
        <p style="text-align: center; margin: 30px 0;">
            <a href="' . esc_url($b['frontend_url']) . '" class="button">Visitar nuestro sitio web</a>
        </p>
        
        <p><em>Gracias por contactarnos. Valoramos tu tiempo y te responderemos lo antes posible.</em></p>
    ';

    $footer = 'Atentamente,<br><strong>El equipo de ' . esc_html($b['site_name']) . '</strong>';
    $message = starter_email_wrap($b, 'Mensaje recibido correctamente', $body, $footer);
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mail_sent = wp_mail($form_data['email'], $subject, $message, $headers);
    
    if ($mail_sent) {
        error_log("Confirmación de contacto enviada a: " . $form_data['email'] . " (Nombre: " . $form_data['name'] . ")");
    } else {
        error_log("Error al enviar confirmación de contacto a: " . $form_data['email'] . " (Nombre: " . $form_data['name'] . ")");
    }
    return $mail_sent;
}