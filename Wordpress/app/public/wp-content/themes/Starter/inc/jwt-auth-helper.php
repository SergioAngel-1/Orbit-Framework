<?php
/**
 * Helper para autenticación JWT en endpoints REST
 * 
 * PROBLEMA RESUELTO:
 * WordPress mantiene cookies de sesión que persisten incluso después de que el frontend
 * elimina el token JWT. Esto causaba que usuarios "deslogueados" en el frontend siguieran
 * viendo contenido de membresía porque get_current_user_id() usaba las cookies.
 * 
 * SOLUCIÓN:
 * Esta función verifica explícitamente si hay un token Bearer JWT en la petición.
 * Si no hay token, retorna 0 (usuario anónimo) independientemente de las cookies.
 * 
 * @package Starter
 * @since 1.5.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verifica si hay un token JWT válido en la petición REST
 * Si no hay token Bearer, retorna 0 (usuario anónimo) independientemente de las cookies de sesión
 * 
 * MEJORA v1.6.0: Decodifica el JWT directamente usando la misma librería Firebase JWT
 * del plugin jwt-auth, en vez de depender de get_current_user_id() que requiere que el
 * filtro determine_current_user ya haya ejecutado. Esto elimina la race condition
 * intermitente donde el usuario se resolvía como 0 en peticiones concurrentes.
 * 
 * USO: Reemplazar get_current_user_id() por starter_get_jwt_user_id() en endpoints REST
 * que dependen del nivel de membresía del usuario.
 * 
 * @return int ID del usuario si hay token JWT válido, 0 si no hay token o es inválido
 */
function starter_get_jwt_user_id() {
    // Cache estático para evitar decodificar el JWT múltiples veces en la misma petición
    static $cached_user_id = null;
    if ($cached_user_id !== null) {
        return $cached_user_id;
    }
    
    // Verificar si hay header de autorización Bearer
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    
    // También verificar el header alternativo (algunos servidores lo usan)
    if (empty($auth_header) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    
    // Si no hay header Bearer, el usuario es anónimo (nivel 0)
    if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
        $cached_user_id = 0;
        return 0;
    }
    
    // Extraer el token del header
    $token_string = trim(substr($auth_header, 7));
    if (empty($token_string)) {
        $cached_user_id = 0;
        return 0;
    }
    
    // ESTRATEGIA 1: Decodificar JWT directamente (no depende de determine_current_user)
    $user_id = starter_decode_jwt_user_id($token_string);
    if ($user_id > 0) {
        $cached_user_id = $user_id;
        return $user_id;
    }
    
    // ESTRATEGIA 2 (fallback): Confiar en get_current_user_id() si la decodificación directa falla
    // (puede fallar si la librería JWT no está cargada aún o la key no está configurada)
    $user_id = get_current_user_id();
    $cached_user_id = $user_id;
    return $user_id;
}

/**
 * Decodifica un token JWT directamente para extraer el user_id
 * Usa la misma librería Firebase JWT del plugin jwt-authentication-for-wp-rest-api
 * 
 * @param string $token_string Token JWT sin el prefijo "Bearer "
 * @return int User ID si el token es válido, 0 si no
 */
function starter_decode_jwt_user_id($token_string) {
    // Verificar que la secret key esté configurada
    if (!defined('JWT_AUTH_SECRET_KEY') || !JWT_AUTH_SECRET_KEY) {
        return 0;
    }
    
    // Verificar que la librería JWT esté disponible
    if (!class_exists('Tmeister\Firebase\JWT\JWT') || !class_exists('Tmeister\Firebase\JWT\Key')) {
        return 0;
    }
    
    try {
        $secret_key = JWT_AUTH_SECRET_KEY;
        $algorithm = apply_filters('jwt_auth_algorithm', 'HS256');
        
        $decoded = \Tmeister\Firebase\JWT\JWT::decode(
            $token_string, 
            new \Tmeister\Firebase\JWT\Key($secret_key, $algorithm)
        );
        
        // Verificar que el token contenga el user id
        if (isset($decoded->data->user->id)) {
            $user_id = intval($decoded->data->user->id);
            // Verificar que el usuario existe en WordPress
            if ($user_id > 0 && get_userdata($user_id) !== false) {
                return $user_id;
            }
        }
    } catch (\Exception $e) {
        // Token inválido, expirado, etc. — retornar 0
        // No loggeamos para evitar spam en producción (tokens expirados son normales)
        return 0;
    }
    
    return 0;
}

/**
 * Obtiene el nivel de membresía del usuario autenticado por JWT
 * Combina la verificación JWT con la obtención del nivel de membresía
 * 
 * @return int Nivel de membresía (0-5). 0 si no hay token JWT o usuario no tiene membresía.
 */
function starter_get_jwt_user_membership_level() {
    $user_id = starter_get_jwt_user_id();
    
    if (!$user_id) {
        return 0;
    }
    
    if (function_exists('starter_get_user_membership_level')) {
        return starter_get_user_membership_level($user_id);
    }
    
    return 0;
}
