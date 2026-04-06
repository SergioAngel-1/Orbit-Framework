<?php
/**
 * Template de correo para restablecimiento de contraseña
 * 
 * Usa branding dinámico via site_get_email_branding() y starter_email_wrap().
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generar el HTML del correo de restablecimiento de contraseña
 * 
 * @param string $user_display_name Nombre del usuario
 * @param string $reset_url URL de restablecimiento
 * @param string $logo_url URL del logo (deprecated, se ignora - usa Site Settings)
 * @return string HTML del correo
 */
function starter_get_password_reset_email_html($user_display_name, $reset_url, $logo_url = '') {
    $b = function_exists('site_get_email_branding') ? site_get_email_branding() : starter_get_branding();
    
    $body = '
        <p>Hola <strong>' . esc_html($user_display_name) . '</strong>,</p>

        <p>Has solicitado restablecer tu contraseña en tu cuenta de <strong>' . esc_html($b['site_name']) . '</strong>.</p>

        <p>Para completar el proceso y crear una nueva contraseña, haz clic en el siguiente botón:</p>

        <p style="text-align: center;"><a href="' . esc_url($reset_url) . '" class="button">Restablecer mi contraseña</a></p>

        <p>O si prefieres, copia y pega este enlace en tu navegador:</p>
        <p><a href="' . esc_url($reset_url) . '" class="link">' . esc_html($reset_url) . '</a></p>

        <p><em>Este enlace es válido por 24 horas. Si no solicitaste este cambio, puedes ignorar este mensaje y tu contraseña seguirá siendo la misma.</em></p>
    ';

    $footer = 'Gracias por ser parte de nuestra comunidad,<br><strong>El equipo de ' . esc_html($b['site_name']) . '</strong>';
    return starter_email_wrap($b, 'Restablecimiento de Contraseña', $body, $footer);
}

/**
 * Enviar correo de restablecimiento de contraseña usando el template
 * 
 * @param string $email Email del usuario
 * @param string $user_display_name Nombre del usuario
 * @param string $reset_url URL de restablecimiento
 * @return bool
 */
function starter_send_password_reset_email($email, $user_display_name, $reset_url) {
    $b = function_exists('site_get_email_branding') ? site_get_email_branding() : starter_get_branding();
    
    $subject = 'Restablecimiento de contraseña para ' . $b['site_name'];
    $message = starter_get_password_reset_email_html($user_display_name, $reset_url);
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $mail_sent = wp_mail($email, $subject, $message, $headers);
    
    if ($mail_sent) {
        error_log('Correo de restablecimiento enviado a: ' . $email);
    } else {
        error_log('Error al enviar correo de restablecimiento a: ' . $email);
    }
    return $mail_sent;
}
