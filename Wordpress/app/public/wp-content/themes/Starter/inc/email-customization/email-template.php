<?php
/**
 * Template base para correos electrónicos con branding dinámico
 * 
 * Centraliza estilos CSS, header y footer de emails.
 * Usa site_get_email_branding() para obtener colores, logo, fuente, etc.
 * 
 * @package Starter
 * @subpackage EmailCustomization
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Genera el bloque CSS inline para emails usando branding del sitio
 *
 * @param array $b Branding array de site_get_email_branding()
 * @return string CSS como string
 */
function starter_email_css($b) {
    return '
        @import url("' . esc_url($b['font_import']) . '");
        
        body {
            font-family: "' . esc_attr($b['font']) . '", sans-serif;
            color: #333333;
            line-height: 1.6;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: auto;
            padding: 30px;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            background-color: #FFFFFF;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid ' . esc_attr($b['border_color']) . ';
            padding-bottom: 20px;
        }
        .logo {
            font-size: 24px;
            font-weight: 700;
            color: ' . esc_attr($b['primary_color']) . ';
            margin: 10px 0 0 0;
        }
        .subtitle {
            color: ' . esc_attr($b['secondary_color']) . ';
            font-size: 16px;
            margin-top: 5px;
        }
        a.button {
            display: inline-block;
            background-color: ' . esc_attr($b['primary_color']) . ';
            color: #FFFFFF !important;
            padding: 12px 25px;
            border-radius: 8px;
            text-decoration: none;
            margin: 20px 0;
            font-weight: 600;
            text-align: center;
        }
        a.button:hover {
            background-color: ' . esc_attr($b['hover_color']) . ';
        }
        .link {
            color: ' . esc_attr($b['primary_color']) . ';
            word-break: break-all;
            font-size: 14px;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid ' . esc_attr($b['border_color']) . ';
            text-align: center;
            font-size: 14px;
            color: #666666;
        }
        .highlight-box {
            background-color: #f8f9fa;
            border-left: 4px solid ' . esc_attr($b['primary_color']) . ';
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .status-pending {
            background-color: #FFF3CD;
            border-left-color: #FFC107;
            color: #856404;
        }
        .success-box {
            background-color: #D4EDDA;
            border-left: 4px solid #28A745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            color: #155724;
        }
        .rejection-box {
            background-color: #F8D7DA;
            border-left: 4px solid #DC3545;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            color: #721C24;
        }
        .info-box {
            background-color: #E7F3FF;
            border-left: 4px solid #007CBA;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            color: #004085;
        }
    ';
}

/**
 * Genera el header HTML del email (logo + título + subtítulo)
 *
 * @param array  $b        Branding array
 * @param string $subtitle Subtítulo del email
 * @return string HTML
 */
function starter_email_header($b, $subtitle = '') {
    $logo_html = '';
    if (!empty($b['logo_url'])) {
        $logo_html = '<img src="' . esc_url($b['logo_url']) . '" alt="' . esc_attr($b['site_name']) . ' Logo" style="max-height: 60px; max-width: 200px; margin-bottom: 10px;" />';
    }
    
    $subtitle_html = '';
    if (!empty($subtitle)) {
        $subtitle_html = '<p class="subtitle">' . esc_html($subtitle) . '</p>';
    }
    
    return '
        <div class="header">
            ' . $logo_html . '
            <h1 class="logo">' . esc_html($b['site_name']) . '</h1>
            ' . $subtitle_html . '
        </div>';
}

/**
 * Genera el footer HTML del email
 *
 * @param array  $b       Branding array
 * @param string $message Mensaje personalizado del footer (opcional)
 * @return string HTML
 */
function starter_email_footer($b, $message = '') {
    $unique_id = uniqid('starter_email_', true);
    $timestamp = time();
    
    if (empty($message)) {
        $message = 'Gracias por ser parte de nuestra comunidad,<br><strong>El equipo de ' . esc_html($b['site_name']) . '</strong>';
    }
    
    return '
        <div class="footer">
            <p>' . $message . '</p>
            <p style="font-size: 12px; color: #999;">
                Este correo fue enviado automáticamente. Por favor, no respondas a este mensaje.
            </p>
            <!-- Gmail Anti-Clipping Spacer -->
            <div style="color: transparent; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px; opacity: 0; overflow: hidden;">
                ' . str_repeat('&nbsp;', 100) . ' Ref: ' . $unique_id . ' - ' . $timestamp . '
            </div>
        </div>';
}

/**
 * Envuelve contenido en el template base de email
 *
 * @param array  $b        Branding array de site_get_email_branding()
 * @param string $subtitle Subtítulo en el header
 * @param string $body     Contenido HTML del cuerpo
 * @param string $footer   Mensaje del footer (opcional, usa default)
 * @return string HTML completo del email
 */
function starter_email_wrap($b, $subtitle, $body, $footer = '') {
    return '
    <html>
    <head>
        <meta charset="UTF-8">
        <!-- Gmail Anti-Clipping: ' . uniqid('starter_', true) . ' -->
        <style>' . starter_email_css($b) . '</style>
    </head>
    <body>
        <div class="container">
            ' . starter_email_header($b, $subtitle) . '
            ' . $body . '
            ' . starter_email_footer($b, $footer) . '
        </div>
    </body>
    </html>';
}
