<?php
/**
 * Registro del menú de administración para Membresías
 * 
 * @package Starter_Memberships
 * @subpackage Admin/Core
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Starter_Admin_Menu {
    
    /**
     * Registrar menú de administración
     */
    public static function register() {
        add_action('admin_menu', [__CLASS__, 'add_menu_pages']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }
    
    /**
     * Agregar páginas de menú
     */
    public static function add_menu_pages() {
        // Menú principal
        add_menu_page(
            __('Membresías', 'starter-memberships'),
            __('Membresías', 'starter-memberships'),
            'manage_options',
            'starter-memberships',
            [Starter_Dashboard_Page::class, 'render'],
            'dashicons-id-alt',
            56
        );
        
        // Submenú: Dashboard
        add_submenu_page(
            'starter-memberships',
            __('Dashboard', 'starter-memberships'),
            __('Dashboard', 'starter-memberships'),
            'manage_options',
            'starter-memberships',
            [Starter_Dashboard_Page::class, 'render']
        );
        
        // Submenú: Configuración
        add_submenu_page(
            'starter-memberships',
            __('Configuración', 'starter-memberships'),
            __('Configuración', 'starter-memberships'),
            'manage_options',
            'starter-memberships-settings',
            [Starter_Settings_Page::class, 'render']
        );
        
        // Submenú: Niveles
        add_submenu_page(
            'starter-memberships',
            __('Niveles', 'starter-memberships'),
            __('Niveles', 'starter-memberships'),
            'manage_options',
            'starter-memberships-levels',
            [Starter_Levels_Page::class, 'render']
        );
        
        // Submenú: Beneficios
        add_submenu_page(
            'starter-memberships',
            __('Beneficios', 'starter-memberships'),
            __('Beneficios', 'starter-memberships'),
            'manage_options',
            'starter-memberships-benefits',
            'starter_memberships_benefits_page'
        );
        
        // Submenú: Membresía por Antigüedad
        add_submenu_page(
            'starter-memberships',
            __('Por Antigüedad', 'starter-memberships'),
            __('🏆 Por Antigüedad', 'starter-memberships'),
            'manage_options',
            'starter-memberships-legacy',
            'starter_memberships_legacy_page'
        );
    }
    
    /**
     * Registrar configuraciones
     */
    public static function register_settings() {
        register_setting('starter_memberships_settings', 'starter_memberships_settings');
    }
}
