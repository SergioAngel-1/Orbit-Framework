<?php
/**
 * Panel de administración para Starter Memberships
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar panel de administración
 */
function starter_memberships_init_admin_panel() {
    // Agregar estilos inline para el admin
    add_action('admin_head', 'starter_memberships_admin_styles');
}

/**
 * Estilos CSS para el admin
 */
function starter_memberships_admin_styles() {
    $screen = get_current_screen();
    
    if (!$screen || strpos($screen->id, 'starter-memberships') === false) {
        return;
    }
    
    ?>
    <style>
        .starter-dashboard .card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        
        .starter-dashboard .card h3 {
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .widefat td, .widefat th {
            vertical-align: middle;
        }
    </style>
    <?php
}
