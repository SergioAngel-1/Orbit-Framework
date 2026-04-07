<?php
/**
 * Módulo Core de Administración de Membresías
 * 
 * Carga los componentes principales del admin refactorizado.
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Core
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar servicios
require_once __DIR__ . '/class-stats-service.php';
require_once __DIR__ . '/class-admin-menu.php';

// Cargar páginas
require_once dirname(__DIR__) . '/pages/class-dashboard-page.php';
require_once dirname(__DIR__) . '/pages/class-settings-page.php';
require_once dirname(__DIR__) . '/pages/class-levels-page.php';

/**
 * Inicializar módulo admin core
 */
function starter_admin_core_init() {
    if (!is_admin()) {
        return;
    }
    
    Starter_Admin_Menu::register();
}
add_action('init', 'starter_admin_core_init');

// =========================================================================
// FUNCIONES WRAPPER PARA COMPATIBILIDAD
// =========================================================================

/**
 * Inicializar funciones administrativas (compatibilidad)
 * 
 * @deprecated Usar Starter_Admin_Menu::register() directamente
 */
function starter_memberships_init_admin() {
    // Ya no es necesario, se inicializa automáticamente
}

/**
 * Agregar menú de administración (compatibilidad)
 * 
 * @deprecated Usar Starter_Admin_Menu::add_menu_pages() directamente
 */
function starter_memberships_admin_menu() {
    Starter_Admin_Menu::add_menu_pages();
}

/**
 * Registrar configuraciones (compatibilidad)
 * 
 * @deprecated Usar Starter_Admin_Menu::register_settings() directamente
 */
function starter_memberships_register_settings() {
    Starter_Admin_Menu::register_settings();
}

/**
 * Página de Dashboard (compatibilidad)
 */
function starter_memberships_dashboard_page() {
    Starter_Dashboard_Page::render();
}

/**
 * Página de Configuración (compatibilidad)
 */
function starter_memberships_settings_page() {
    Starter_Settings_Page::render();
}

/**
 * Página de Niveles (compatibilidad)
 */
function starter_memberships_levels_page() {
    Starter_Levels_Page::render();
}

/**
 * Obtener estadísticas de membresías (compatibilidad)
 * 
 * @return array
 */
function starter_memberships_get_stats() {
    return Starter_Stats_Service::get_stats();
}
