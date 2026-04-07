<?php
/**
 * Correos de notificación para nuevos usuarios registrados
 * 
 * Maneja el envío de correos de notificación a usuarios que se registran
 * en el sitio. Usa branding dinámico via site_get_email_branding().
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener branding para emails con fallback seguro
 */
function starter_get_branding() {
    if (function_exists('site_get_email_branding')) {
        return site_get_email_branding();
    }
    return [
        'site_name' => 'Mi Tienda', 'logo_url' => '', 'primary_color' => '#16a34a',
        'secondary_color' => '#FF6B35', 'hover_color' => '#0f7a2f', 'light_bg' => 'rgba(22,163,74,0.08)',
        'border_color' => 'rgba(22,163,74,0.25)', 'font' => 'Poppins',
        'font_import' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap',
        'contact_email' => get_option('admin_email'), 'frontend_url' => home_url(),
    ];
}

/**
 * Enviar correo de notificación de registro en revisión al usuario
 * 
 * @param int $user_id ID del usuario
 * @param string $username Nombre de usuario
 * @param string $email Email del usuario
 * @param string $referral_code Código de referido usado (opcional)
 * @return bool
 */
function starter_send_welcome_email($user_id, $username, $email, $referral_code = '') {
    $user = get_user_by('ID', $user_id);
    if (!$user) {
        error_log("Error: No se pudo obtener el usuario con ID: $user_id");
        return false;
    }
    
    $display_name = $user->display_name ?: $username;
    $b = starter_get_branding();
    
    $subject = 'Registro recibido - Cuenta en proceso de revisión | ' . $b['site_name'];
    
    $referral_html = '';
    if (!empty($referral_code)) {
        $referral_html = '<li><strong>Código de referido usado:</strong> ' . esc_html($referral_code) . '</li>';
    }
    
    $body = '
        <p>Hola <strong>' . esc_html($display_name) . '</strong>,</p>

        <p>Hemos recibido tu solicitud de registro en <strong>' . esc_html($b['site_name']) . '</strong>. Tu cuenta está actualmente en proceso de revisión por nuestro equipo.</p>

        <div class="highlight-box status-pending">
            <h3 style="margin-top: 0; color: #856404;">⏳ Estado actual: En revisión</h3>
            <p style="margin-bottom: 0;">Tu solicitud de registro está siendo revisada por nuestro equipo de administración. Te contactaremos por este mismo correo una vez que el proceso de verificación haya sido completado.</p>
        </div>

        <h3 style="color: ' . esc_attr($b['primary_color']) . ';">📧 Detalles de tu registro:</h3>
        <ul style="background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; list-style: none;">
            <li><strong>Usuario:</strong> ' . esc_html($username) . '</li>
            <li><strong>Email:</strong> ' . esc_html($email) . '</li>
            <li><strong>Fecha de registro:</strong> ' . date('d/m/Y H:i') . '</li>
            ' . $referral_html . '
        </ul>

        <h3 style="color: ' . esc_attr($b['primary_color']) . ';">🔍 Proceso de revisión</h3>
        <ol style="padding-left: 20px;">
            <li><strong>Verificación:</strong> Nuestro equipo verificará la información proporcionada (24-48 horas)</li>
            <li><strong>Evaluación:</strong> Se revisará que cumples con nuestros términos y condiciones</li>
            <li><strong>Notificación:</strong> Recibirás un correo con el resultado de la revisión</li>
            <li><strong>Activación:</strong> Si es aprobada, podrás acceder inmediatamente a tu cuenta</li>
        </ol>

        <div class="highlight-box">
            <h3 style="margin-top: 0; color: ' . esc_attr($b['primary_color']) . ';">💡 Para agilizar el proceso...</h3>
            <p>Puedes pedirle a la persona que te contactó con nosotros que nos escriba indicando que eres el usuario que se registró.</p>
        </div>
        
        <p style="text-align: center; margin: 30px 0; font-size: 14px; color: #666;">
            <em>Nota: No podrás iniciar sesión hasta que tu cuenta sea aprobada</em>
        </p>

        <p><em>Si tienes alguna pregunta sobre el proceso de revisión, puedes contactarnos a través de nuestros canales oficiales.</em></p>
    ';

    $footer = 'Gracias por tu interés en ' . esc_html($b['site_name']) . ',<br><strong>El equipo de revisión de ' . esc_html($b['site_name']) . '</strong>';
    $message = starter_email_wrap($b, 'Registro recibido correctamente', $body, $footer);
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mail_sent = wp_mail($email, $subject, $message, $headers);
    
    if ($mail_sent) {
        error_log("Correo de registro en revisión enviado exitosamente a: $email (Usuario: $username)");
    } else {
        error_log("Error al enviar correo de registro en revisión a: $email (Usuario: $username)");
    }
    return $mail_sent;
}

/**
 * Enviar correo de aprobación cuando un usuario es aprobado
 * 
 * @param int $user_id ID del usuario
 * @return bool
 */
function starter_send_account_approved_email($user_id) {
    $user = get_user_by('ID', $user_id);
    if (!$user) {
        error_log("Error: No se pudo obtener el usuario con ID: $user_id");
        return false;
    }
    
    $username = $user->user_login;
    $email = $user->user_email;
    $display_name = $user->display_name ?: $username;
    $b = starter_get_branding();
    $login_url = rtrim($b['frontend_url'], '/') . '/login';
    
    $subject = '¡Tu cuenta en ' . $b['site_name'] . ' ha sido aprobada! 🎉';
    
    $vc_name = function_exists('site_get_vc_name') ? site_get_vc_name() : 'Virtual Coins';
    
    $body = '
        <p>¡Hola <strong>' . esc_html($display_name) . '</strong>!</p>

        <div class="success-box">
            <h3 style="margin-top: 0;">🎉 ¡Excelentes noticias!</h3>
            <p style="margin-bottom: 0;">Tu cuenta en <strong>' . esc_html($b['site_name']) . '</strong> ha sido aprobada y ya puedes acceder a todas nuestras funcionalidades.</p>
        </div>

        <p>Ahora puedes:</p>
        <ul style="padding-left: 20px;">
            <li>🛒 Explorar y solicitar nuestros productos</li>
            <li>👥 Acceder al sistema de referidos</li>
            <li>💰 Acumular y usar ' . esc_html($vc_name) . '</li>
            <li>📱 Disfrutar de todas las funcionalidades de la plataforma</li>
        </ul>

        <p style="text-align: center; margin: 30px 0;">
            <a href="' . esc_url($login_url) . '" class="button">Iniciar sesión ahora</a>
        </p>

        <p>¡Gracias por tu paciencia y bienvenido oficialmente a <strong>' . esc_html($b['site_name']) . '</strong>!</p>
    ';

    $footer = '¡Disfruta explorando!<br><strong>El equipo de ' . esc_html($b['site_name']) . '</strong>';
    $message = starter_email_wrap($b, '¡Tu cuenta ha sido aprobada!', $body, $footer);
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mail_sent = wp_mail($email, $subject, $message, $headers);
    
    if ($mail_sent) {
        error_log("Correo de aprobación enviado exitosamente a: $email (Usuario: $username)");
    } else {
        error_log("Error al enviar correo de aprobación a: $email (Usuario: $username)");
    }
    return $mail_sent;
}

/**
 * Enviar correo de rechazo cuando un usuario es rechazado
 * 
 * @param int $user_id ID del usuario
 * @return bool
 */
function starter_send_account_rejected_email($user_id) {
    $user = get_user_by('ID', $user_id);
    if (!$user) {
        error_log("Error: No se pudo obtener el usuario con ID: $user_id");
        return false;
    }
    
    $username = $user->user_login;
    $email = $user->user_email;
    $display_name = $user->display_name ?: $username;
    $b = starter_get_branding();
    
    $subject = 'Solicitud de registro no aprobada | ' . $b['site_name'];
    
    $body = '
        <p>Hola <strong>' . esc_html($display_name) . '</strong>,</p>

        <div class="rejection-box">
            <h3 style="margin-top: 0;">❌ Solicitud no aprobada</h3>
            <p style="margin-bottom: 0;">Lamentamos informarte que tu solicitud de registro en <strong>' . esc_html($b['site_name']) . '</strong> no ha sido aprobada en esta ocasión.</p>
        </div>

        <h3 style="color: ' . esc_attr($b['primary_color']) . ';">🤔 ¿Por qué puede haber ocurrido esto?</h3>
        <ul style="padding-left: 20px;">
            <li>Información incompleta o incorrecta en el registro</li>
            <li>No cumplimiento de nuestros términos y condiciones</li>
            <li>Problemas de verificación de identidad</li>
            <li>Otras razones administrativas</li>
        </ul>

        <div class="info-box">
            <h3 style="margin-top: 0; color: #004085;">💡 ¿Qué puedes hacer?</h3>
            <p>Si crees que esto es un error o deseas más información sobre el motivo del rechazo, puedes pedirle a la persona que te contactó con nosotros que nos escriba indicando que eres el usuario que se registró.</p>
            <p style="margin-bottom: 0;">Nuestro equipo de soporte estará encantado de ayudarte y aclarar cualquier duda.</p>
        </div>

        <p><em>Agradecemos tu interés en ' . esc_html($b['site_name']) . ' y lamentamos cualquier inconveniente que esto pueda causar.</em></p>
    ';

    $footer = 'Gracias por tu comprensión,<br><strong>El equipo de ' . esc_html($b['site_name']) . '</strong>';
    $message = starter_email_wrap($b, 'Resultado de la revisión', $body, $footer);
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mail_sent = wp_mail($email, $subject, $message, $headers);
    
    if ($mail_sent) {
        error_log("Correo de rechazo enviado exitosamente a: $email (Usuario: $username)");
    } else {
        error_log("Error al enviar correo de rechazo a: $email (Usuario: $username)");
    }
    return $mail_sent;
}