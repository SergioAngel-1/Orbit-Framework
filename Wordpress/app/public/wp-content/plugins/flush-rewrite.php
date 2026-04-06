<?php
/**
 * Plugin Name: Flush Rewrite Rules Once
 * Description: Fuerza la actualización de las reglas de reescritura una vez y luego se desactiva
 * Version: 1.0
 * Author: Admin
 */

// Si esto está siendo llamado directamente, abortar
if (!defined('WPINC')) {
    die;
}

// Función para forzar la actualización de las reglas
function starter_flush_rewrites() {
    global $wp_rewrite;
    
    // Forzar la actualización de las reglas de reescritura
    flush_rewrite_rules(true);
    
    // Desactivar este plugin después de ejecutarse
    deactivate_plugins(plugin_basename(__FILE__));
}

// Agregar acción para cuando WordPress esté completamente cargado
add_action('wp_loaded', 'starter_flush_rewrites');
