<?php
/**
 * Registro de ubicaciones de menú
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar ubicaciones de menú
 */
function starter_register_menus() {
    register_nav_menus(
        array(
            'main-menu' => __('Menú Principal', 'starter'),
            'footer-menu' => __('Menú de Pie de Página', 'starter')
        )
    );
}
add_action('init', 'starter_register_menus');
