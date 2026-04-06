<?php
/**
 * Personalización de correos electrónicos
 * 
 * Maneja la personalización del remitente y diseño de los correos
 * electrónicos enviados por WordPress. Usa branding dinámico de Site Settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtener la URL del logo para emails con sistema de respaldos
 * Primero intenta Site Settings, luego fallbacks del tema
 * 
 * @return string URL del logo
 */
function starter_get_email_logo_url() {
    // 1. Logo de Site Settings (plugin)
    if (function_exists('site_get_logo_url')) {
        $logo = site_get_logo_url();
        if (!empty($logo)) {
            return $logo;
        }
    }

    // 2. Fallback: logo en el tema
    $theme_logo_path = get_template_directory() . '/assets/images/logo.png';
    if (file_exists($theme_logo_path)) {
        return get_template_directory_uri() . '/assets/images/logo.png';
    }

    // 3. Fallback: custom logo de WordPress
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $url = wp_get_attachment_image_url($custom_logo_id, 'full');
        if ($url) {
            return $url;
        }
    }

    return '';
}

/**
 * Personalizar el nombre del remitente de los correos electrónicos
 */
function starter_mail_from_name($original_email_from_name) {
    if ($original_email_from_name === 'WordPress' || empty($original_email_from_name)) {
        return function_exists('site_get_name') ? site_get_name() : 'Mi Tienda';
    }
    return $original_email_from_name;
}

/**
 * Personalizar la dirección de correo del remitente
 */
function starter_mail_from_address($original_email_address) {
    $sitename = wp_parse_url(network_home_url(), PHP_URL_HOST);
    
    if (strpos($original_email_address, 'wordpress@') === 0 || empty($original_email_address)) {
        if ($sitename) {
            if (str_starts_with($sitename, 'www.')) {
                $sitename = substr($sitename, 4);
            }
            return 'noreply@' . $sitename;
        }
        return function_exists('site_get_contact_email') ? site_get_contact_email() : 'noreply@example.com';
    }
    
    return $original_email_address;
}

/**
 * Personalizar correos de restablecimiento de contraseña
 */
function starter_password_reset_mail_filter($args) {
    if (isset($args['subject']) && 
        (strpos($args['subject'], 'Restablecimiento de contraseña') !== false || 
         strpos($args['subject'], 'Password Reset') !== false)) {
        
        $site_name = function_exists('site_get_name') ? site_get_name() : 'Mi Tienda';
        add_filter('wp_mail_from_name', function() use ($site_name) {
            return $site_name;
        }, 99);
        
        add_filter('wp_mail_from', function() {
            $sitename = wp_parse_url(network_home_url(), PHP_URL_HOST);
            if ($sitename && str_starts_with($sitename, 'www.')) {
                $sitename = substr($sitename, 4);
            }
            return $sitename ? 'noreply@' . $sitename : 'noreply@example.com';
        }, 99);
    }
    
    return $args;
}

/**
 * Asegurar que los correos sean HTML
 */
function starter_set_html_content_type($content_type) {
    if (doing_action('wp_mail')) {
        return 'text/html';
    }
    return $content_type;
}

/**
 * Inicializar las personalizaciones de correo
 */
function starter_init_email_customization() {
    add_filter('wp_mail_from_name', 'starter_mail_from_name');
    add_filter('wp_mail_from', 'starter_mail_from_address');
    add_filter('wp_mail', 'starter_password_reset_mail_filter');
    add_filter('wp_mail_content_type', 'starter_set_html_content_type');
}

add_action('init', 'starter_init_email_customization');

/**
 * Función de prueba para enviar un correo de prueba (solo para desarrollo)
 */
function starter_test_email_customization() {
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para realizar esta acción');
    }
    
    $b = function_exists('site_get_email_branding') ? site_get_email_branding() : [
        'site_name' => 'Mi Tienda', 'logo_url' => '', 'primary_color' => '#16a34a',
        'secondary_color' => '#FF6B35', 'hover_color' => '#0f7a2f', 'light_bg' => 'rgba(22,163,74,0.08)',
        'border_color' => 'rgba(22,163,74,0.25)', 'font' => 'Poppins',
        'font_import' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap',
        'contact_email' => get_option('admin_email'), 'frontend_url' => home_url(),
    ];
    
    $admin_email = get_option('admin_email');
    $subject = 'Prueba de personalización de correos - ' . $b['site_name'];
    
    $body = '
        <p>Este es un correo de prueba para verificar la personalización de <strong>' . esc_html($b['site_name']) . '</strong>.</p>
        <p>Si ves "' . esc_html($b['site_name']) . '" como remitente y los colores de tu marca, la personalización funciona correctamente.</p>
        <p style="text-align: center;"><a href="' . esc_url($b['frontend_url']) . '" class="button">Visitar el sitio</a></p>
    ';
    
    $message = function_exists('starter_email_wrap') 
        ? starter_email_wrap($b, 'Correo de Prueba', $body) 
        : '<html><body>' . $body . '</body></html>';
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $result = wp_mail($admin_email, $subject, $message, $headers);
    
    wp_die($result 
        ? 'Correo de prueba enviado correctamente a: ' . $admin_email 
        : 'Error al enviar el correo de prueba'
    );
}

if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('wp_ajax_starter_test_email', 'starter_test_email_customization');
}