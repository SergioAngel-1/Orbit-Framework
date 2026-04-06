<?php
/**
 * Gestión de caché para el perfil de usuario
 * 
 * Este archivo contiene las funciones relacionadas con la gestión
 * de caché para los datos del perfil de usuario.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Función vacía para mantener compatibilidad - W3 Total Cache se encargará del caché
 */
function invalidate_user_profile_cache($meta_id, $user_id, $meta_key, $meta_value) {
    // No hace nada - W3 Total Cache se encargará de esto
}
add_action('updated_user_meta', 'invalidate_user_profile_cache', 10, 4);
add_action('added_user_meta', 'invalidate_user_profile_cache', 10, 4);
add_action('deleted_user_meta', 'invalidate_user_profile_cache', 10, 4);
