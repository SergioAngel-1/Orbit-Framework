<?php
/**
 * Helper de autenticación JWT para Home Sections
 */

if (!defined('ABSPATH')) {
    exit;
}

class FIHS_Auth_Helper {
    
    /**
     * Verifica si hay un token JWT válido en la petición REST
     * Si no hay token Bearer, retorna 0 (usuario anónimo)
     * 
     * @return int ID del usuario si hay token JWT válido, 0 si no hay token
     */
    public static function get_jwt_user_id() {
        $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
        
        if (empty($auth_header) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        
        if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
            return 0;
        }
        
        return get_current_user_id();
    }
    
    /**
     * Obtener nivel de membresía del usuario actual (vía JWT)
     * 
     * @return int Nivel de membresía (0 para anónimos)
     */
    public static function get_user_membership_level() {
        $user_id = self::get_jwt_user_id();
        
        if ($user_id && function_exists('starter_get_user_membership_level')) {
            return starter_get_user_membership_level($user_id);
        }
        
        return 0;
    }
}
