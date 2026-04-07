<?php
/**
 * Clase para gestionar el menú de administración
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class Starter_Home_Sections_Admin_Menu {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Registrar el menú de administración
        add_action('admin_menu', array($this, 'register_menu'));
    }
    
    /**
     * Registrar el menú de administración
     */
    public function register_menu() {
        add_menu_page(
            'Secciones de Inicio', 
            'Secciones de Inicio', 
            'manage_options', 
            'starter-home-sections', 
            array($this, 'render_admin_page'), 
            'dashicons-layout', 
            30
        );
    }
    
    /**
     * Página de administración
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Incluir la nueva página de administración
        require_once FIHS_PLUGIN_DIR . 'admin/admin-page.php';
    }
}
