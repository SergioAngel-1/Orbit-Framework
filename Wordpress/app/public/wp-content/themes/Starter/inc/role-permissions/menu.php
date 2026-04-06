<?php
/**
 * Menú de Roles y Permisos
 * 
 * Registra el menú principal y submenús en la sidebar de WordPress
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Añadir menú "Roles y Permisos" en la sidebar de WordPress
 */
function starter_add_roles_permissions_menu() {
    add_menu_page(
        'Roles y Permisos',
        'Roles y Permisos',
        'manage_options',
        'starter-roles-permissions',
        'starter_roles_permissions_page',
        'dashicons-admin-users',
        70
    );
    
    add_submenu_page(
        'starter-roles-permissions',
        'Gestionar Roles',
        'Gestionar Roles',
        'manage_options',
        'starter-roles-permissions',
        'starter_roles_permissions_page'
    );
    
    add_submenu_page(
        'starter-roles-permissions',
        'Crear Nuevo Rol',
        'Crear Nuevo Rol',
        'manage_options',
        'starter-create-role',
        'starter_create_role_page'
    );
    
    add_submenu_page(
        'starter-roles-permissions',
        'Editar Rol',
        null, // Oculto del menú
        'manage_options',
        'starter-edit-role',
        'starter_edit_role_page'
    );
    
    add_submenu_page(
        'starter-roles-permissions',
        'Configurar Sidebar por Rol',
        'Configurar Sidebar',
        'manage_options',
        'starter-configure-sidebar',
        'starter_configure_sidebar_page'
    );
}
add_action('admin_menu', 'starter_add_roles_permissions_menu');
