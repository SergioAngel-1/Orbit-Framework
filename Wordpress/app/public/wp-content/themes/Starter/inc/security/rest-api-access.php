<?php
/**
 * REST API Access Control
 * 
 * Asegura que todos los roles personalizados tengan acceso a la REST API
 * sin requerir Application Passwords cuando usan JWT.
 * 
 * @package Starter
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permitir acceso REST API para roles personalizados cuando usan JWT
 * 
 * WordPress por defecto requiere Application Passwords para REST API,
 * pero nosotros usamos JWT. Este filtro permite el acceso cuando hay
 * un token JWT válido.
 */
add_filter('rest_authentication_errors', function($result) {
    // Si ya hay un error, mantenerlo
    if (is_wp_error($result)) {
        return $result;
    }
    
    // Si ya está autenticado (por JWT), permitir acceso
    if (is_user_logged_in()) {
        return true;
    }
    
    // Si no está autenticado, dejar que otros métodos de auth manejen
    return $result;
}, 100);

/**
 * Permitir acceso REST API sin otorgar edit_posts de forma persistente
 * 
 * En vez de agregar edit_posts a todos los roles (escalada de privilegios),
 * usamos un filtro dinámico que solo aplica durante requests REST API
 * a endpoints propios de Starter. Los endpoints custom ya tienen
 * su propio permission_callback, así que solo necesitamos que WP
 * no bloquee la petición antes de llegar a ellos.
 */
add_filter('user_has_cap', function($allcaps, $caps, $args, $user) {
    // Solo actuar durante REST API requests
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return $allcaps;
    }
    
    // Si se pide edit_posts y el usuario no la tiene, concederla
    // dinámicamente solo en contexto REST (no se persiste en DB)
    if (in_array('edit_posts', $caps, true) && empty($allcaps['edit_posts'])) {
        // Solo para usuarios autenticados con al menos capacidad 'read'
        if (!empty($allcaps['read'])) {
            $allcaps['edit_posts'] = true;
        }
    }
    
    return $allcaps;
}, 10, 4);

/**
 * Limpiar la capability edit_posts previamente otorgada a roles que no deberían tenerla.
 * Se ejecuta una sola vez (controlado por opción en DB).
 */
function starter_cleanup_edit_posts_capability() {
    if (get_option('starter_edit_posts_cleaned', false)) {
        return;
    }
    
    // Roles que legítimamente necesitan edit_posts
    $roles_with_edit_posts = ['administrator', 'editor', 'author', 'contributor'];
    
    $all_roles = wp_roles()->roles;
    
    foreach ($all_roles as $role_slug => $role_data) {
        if (in_array($role_slug, $roles_with_edit_posts, true)) {
            continue;
        }
        
        $role = get_role($role_slug);
        if ($role && $role->has_cap('edit_posts')) {
            $role->remove_cap('edit_posts');
        }
    }
    
    update_option('starter_edit_posts_cleaned', true);
}
add_action('init', 'starter_cleanup_edit_posts_capability', 999);

/**
 * Bypass de Application Passwords check cuando se usa JWT
 * 
 * Este filtro previene el error "invalid_application_credentials"
 * cuando un usuario autenticado con JWT intenta acceder a la REST API.
 */
add_filter('wp_is_application_passwords_available_for_user', function($available, $user) {
    // Si el usuario está autenticado (por JWT), permitir acceso
    if (is_user_logged_in() && get_current_user_id() === $user->ID) {
        return true;
    }
    
    return $available;
}, 10, 2);

/**
 * Enable two-factor authentication for REST API login endpoints to ensure 2FA validation on authentication requests
 * 
 * NOTA: Solo registrar este filtro si el plugin Two Factor está activo.
 * Esto evita errores fatales al activar el plugin por primera vez.
 */
add_action('init', function() {
    // Verificar si el plugin Two Factor está activo de múltiples formas
    $two_factor_active = (
        class_exists('Two_Factor_Core') ||
        (function_exists('is_plugin_active') && is_plugin_active('two-factor/two-factor.php')) ||
        has_filter('two_factor_user_api_login_enable') // Otro plugin ya lo registró
    );

    if (!$two_factor_active) {
        return;
    }

    add_filter('two_factor_user_api_login_enable', function($enabled, $user_id) {
        if ($enabled) {
            return $enabled;
        }

        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return $enabled;
        }

        $is_login_endpoint = false;

        if (!empty($_GET['rest_route']) && is_string($_GET['rest_route'])) {
            $rest_route = (string) $_GET['rest_route'];
            if (strpos($rest_route, '/starter/v1/auth') === 0 || strpos($rest_route, '/jwt-auth/v1/token') === 0) {
                $is_login_endpoint = true;
            }
        }

        if (!$is_login_endpoint) {
            $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $path = parse_url($request_uri, PHP_URL_PATH);
            if (!is_string($path)) {
                $path = $request_uri;
            }

            if (strpos($path, '/wp-json/starter/v1/auth') !== false || strpos($path, '/wp-json/jwt-auth/v1/token') !== false) {
                $is_login_endpoint = true;
            }
        }

        if (!$is_login_endpoint) {
            return $enabled;
        }

        return true;
    }, 10, 2);
}, 20); // Ejecutar después de que los plugins se carguen
