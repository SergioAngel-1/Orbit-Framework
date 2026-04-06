<?php
/**
 * Funciones para gestionar roles y capacidades personalizadas
 * 
 * Este archivo contiene las funciones para configurar el rol "Gestor de la tienda"
 * con permisos específicos, limitando su acceso a ciertas secciones del panel de WordPress.
 */

// Evitar acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Función para configurar el rol "Gestor de la tienda"
 * Esta función se ejecuta durante la activación del tema o cuando se llama explícitamente
 */
function starter_configure_shop_manager_role() {
    // Verificar si el rol ya existe
    $role = get_role('shop_manager');
    
    // Si no existe, crearlo
    if (!$role) {
        add_role(
            'shop_manager',
            'Gestor de la tienda',
            array(
                'read' => true,
                'edit_posts' => true,
                'delete_posts' => false,
                'publish_posts' => false,
                'upload_files' => true,
            )
        );
        $role = get_role('shop_manager');
    }
    
    // Capacidades para WooCommerce
    $woocommerce_caps = array(
        'manage_woocommerce' => true,
        'view_woocommerce_reports' => true,
        'edit_product' => true,
        'read_product' => true,
        'delete_product' => true,
        'edit_products' => true,
        'edit_others_products' => true,
        'publish_products' => true,
        'read_private_products' => true,
        'delete_products' => true,
        'delete_private_products' => true,
        'delete_published_products' => true,
        'delete_others_products' => true,
        'edit_private_products' => true,
        'edit_published_products' => true,
        'manage_product_terms' => true,
        'edit_product_terms' => true,
        'delete_product_terms' => true,
        'assign_product_terms' => true,
        'edit_shop_order' => true,
        'read_shop_order' => true,
        'delete_shop_order' => true,
        'edit_shop_orders' => true,
        'edit_others_shop_orders' => true,
        'publish_shop_orders' => true,
        'read_private_shop_orders' => true,
        'delete_shop_orders' => true,
        'delete_private_shop_orders' => true,
        'delete_published_shop_orders' => true,
        'delete_others_shop_orders' => true,
        'edit_private_shop_orders' => true,
        'edit_published_shop_orders' => true,
        'manage_shop_order_terms' => true,
        'edit_shop_order_terms' => true,
        'delete_shop_order_terms' => true,
        'assign_shop_order_terms' => true,
    );
    
    // Asignar capacidades de WooCommerce
    foreach ($woocommerce_caps as $cap => $grant) {
        $role->add_cap($cap, $grant);
    }
    
    // Capacidades para WordPress
    $wordpress_caps = array(
        'edit_pages' => true,
        'edit_others_pages' => true,
        'edit_published_pages' => true,
        'publish_pages' => true,
        'read_private_pages' => true,
        'edit_theme_options' => false, // No permitir editar temas
        'update_plugins' => false,     // No permitir actualizar plugins
        'install_plugins' => false,    // No permitir instalar plugins
        'activate_plugins' => false,   // No permitir activar plugins
        'update_core' => false,        // No permitir actualizaciones del core
        'list_users' => true,          // Permitir ver usuarios
        'promote_users' => false,      // No permitir promover usuarios
        'remove_users' => false,       // No permitir eliminar usuarios
        'add_users' => false,          // No permitir añadir usuarios
        'create_users' => false,       // No permitir crear usuarios
        'manage_categories' => true,   // Permitir gestionar categorías
    );
    
    // Asignar capacidades de WordPress
    foreach ($wordpress_caps as $cap => $grant) {
        $role->add_cap($cap, $grant);
    }
    
    // Registrar que se ha configurado el rol
    update_option('starter_shop_manager_configured', true);
}

/**
 * Función para ocultar notificaciones de actualización para usuarios no administradores
 */
function starter_hide_update_notices() {
    if (!current_user_can('update_core')) {
        remove_action('admin_notices', 'update_nag', 3);
        remove_action('admin_notices', 'maintenance_nag', 10);
    }
}

// Hooks para ejecutar las funciones
add_action('init', 'starter_configure_shop_manager_role');
add_action('admin_head', 'starter_hide_update_notices', 1);

/**
 * Función para ejecutar durante la activación del tema
 */
function starter_theme_activation() {
    starter_configure_shop_manager_role();
}
register_activation_hook(get_stylesheet_directory() . '/functions.php', 'starter_theme_activation');
