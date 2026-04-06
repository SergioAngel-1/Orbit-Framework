<?php
/**
 * Registro del menú de administración para grillas publicitarias
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar dependencias
require_once dirname(__FILE__) . '/config.php';
require_once dirname(__FILE__) . '/helpers.php';
require_once dirname(__FILE__) . '/actions.php';
require_once dirname(__FILE__) . '/scripts.php';

/**
 * Agregar menú principal para grillas publicitarias
 */
function fipg_add_admin_menu() {
    add_menu_page(
        'Grillas Publicitarias',
        'Grillas Publicitarias',
        'manage_options',
        'promotional-grids',
        'fipg_render_admin_page',
        'dashicons-grid-view',
        56
    );
}
add_action('admin_menu', 'fipg_add_admin_menu');

/**
 * Renderizar la página de administración
 */
function fipg_render_admin_page() {
    require_once dirname(__FILE__) . '/admin-page.php';
}
