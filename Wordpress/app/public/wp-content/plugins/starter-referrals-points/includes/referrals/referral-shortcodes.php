<?php
/**
 * Shortcodes para el sistema de referidos
 * 
 * Funciones para los shortcodes relacionados con referidos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode para mostrar enlace de referido
 * 
 * @param array $atts Atributos del shortcode
 * @return string HTML del enlace de referido
 */
function starter_rp_referral_link_shortcode($atts) {
    $atts = shortcode_atts([
        'class' => 'btn btn-primary',
        'text' => 'Mi enlace de referido',
        'copy_text' => 'Copiar',
        'copied_text' => 'Copiado',
        'show_url' => 'yes'
    ], $atts);
    
    // Verificar si el usuario está logeado
    if (!is_user_logged_in()) {
        return '<p class="alert alert-warning">Debes iniciar sesión para ver tu enlace de referido.</p>';
    }
    
    // Obtener código de referido
    $code = starter_rp_get_user_referral_code();
    
    if (!$code) {
        return '<p class="alert alert-warning">No tienes un código de referido asignado.</p>';
    }
    
    // Construir URL
    $referral_url = home_url('?ref=' . $code);
    
    // Generar HTML
    $output = '<div class="starter-referral-link">';
    
    if ($atts['show_url'] === 'yes') {
        $output .= '<div class="referral-url">' . $referral_url . '</div>';
    }
    
    $output .= '<button class="' . esc_attr($atts['class']) . ' js-copy-referral-link" data-clipboard-text="' . esc_attr($referral_url) . '" data-copy-text="' . esc_attr($atts['copy_text']) . '" data-copied-text="' . esc_attr($atts['copied_text']) . '">' . esc_html($atts['text']) . '</button>';
    $output .= '</div>';
    
    return $output;
}
