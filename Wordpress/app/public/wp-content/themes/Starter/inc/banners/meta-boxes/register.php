<?php
/**
 * Banners - Registro de Meta Boxes
 * 
 * Este archivo contiene el registro de los meta boxes para
 * gestionar los campos personalizados de los banners.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añadir metabox para los campos personalizados del banner
 */
function banner_add_meta_box() {
    add_meta_box(
        'banner_metabox',
        'Configuración del Banner',
        'banner_metabox_callback',
        'banner',
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'banner_add_meta_box');
