<?php
/**
 * Banners - Meta Boxes
 * 
 * Este archivo actúa como punto de entrada para los meta boxes de banners.
 * Ahora utiliza una estructura modular con componentes separados.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los componentes refactorizados
require_once dirname(__FILE__) . '/meta-boxes/register.php';
require_once dirname(__FILE__) . '/meta-boxes/fields-common.php';
require_once dirname(__FILE__) . '/meta-boxes/fields-social.php';
require_once dirname(__FILE__) . '/meta-boxes/fields-carousel.php';
require_once dirname(__FILE__) . '/meta-boxes/assets.php';
require_once dirname(__FILE__) . '/meta-boxes/save.php';
require_once dirname(__FILE__) . '/meta-boxes/info-sidebar.php';

/**
 * Callback para el metabox
 */
function banner_metabox_callback($post) {
    // Obtener valores guardados
    $subtitle = get_post_meta($post->ID, '_banner_subtitle', true);
    $cta = get_post_meta($post->ID, '_banner_cta', true);
    $link = get_post_meta($post->ID, '_banner_link', true);
    $order = get_post_meta($post->ID, '_banner_order', true);
    $image = get_post_meta($post->ID, '_banner_image', true);
    $image_mobile = get_post_meta($post->ID, '_banner_image_mobile', true);
    $type = get_post_meta($post->ID, '_banner_type', true);
    $social_networks = get_post_meta($post->ID, '_banner_social_networks', true);
    $carousel_images = get_post_meta($post->ID, '_banner_carousel_images', true);
    
    // Valores por defecto
    if ($order === '') {
        $order = 0;
    }
    
    if ($type === '') {
        $type = 'main';
    }
    
    // Nonce para seguridad
    wp_nonce_field('starter_banner_metabox', 'starter_banner_metabox_nonce');
    
    // Asegurar que wp.media esté disponible
    wp_enqueue_media();
    
    // Renderizar estilos
    banner_render_styles();
    
    // Renderizar campos comunes
    banner_render_common_fields($post, $type, $subtitle, $cta, $link, $order, $image, $image_mobile);
    
    // Renderizar campos para redes sociales
    banner_render_social_fields($post, $social_networks);
    
    // Renderizar campos para carrusel
    banner_render_carousel_fields($post, $carousel_images);
    
    // Renderizar scripts
    banner_render_scripts($post, $social_networks, $carousel_images);
}