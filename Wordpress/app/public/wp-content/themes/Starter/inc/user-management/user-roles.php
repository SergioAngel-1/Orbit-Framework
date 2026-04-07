<?php
/**
 * Roles de usuario
 * 
 * Este archivo contiene las funciones relacionadas con la creación y gestión
 * de roles de usuario personalizados.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crear rol de usuario rechazado durante la activación del tema
 */
function starter_create_rejected_role() {
    // Comprobar si el rol ya existe para evitar errores
    if (!get_role('rejected')) {
        // Crear un nuevo rol con capacidades mínimas
        add_role(
            'rejected',
            'Rechazado',
            array(
                'read' => true, // Permitir solo lectura básica
            )
        );
    }
}
// Registrar la función para que se ejecute en la activación del tema
add_action('after_switch_theme', 'starter_create_rejected_role');

/**
 * Asegurar que el rol exista al cargar el tema
 */
function starter_ensure_rejected_role() {
    starter_create_rejected_role();
    
    // Verificar que el rol se creó correctamente
    $rejected_role = get_role('rejected');
    if (!$rejected_role) {
        error_log("ERROR: El rol 'rejected' no existe aún después de intentar crearlo");
    }
}
add_action('init', 'starter_ensure_rejected_role', 1); // Prioridad 1 para que se ejecute temprano
