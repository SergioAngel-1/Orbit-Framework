<?php
/**
 * Registro de endpoints para restablecimiento de contraseña
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registrar el endpoint para solicitar restablecimiento de contraseña
 */
function starter_register_password_reset_endpoint() {
    // Registrar la ruta original para compatibilidad
    register_rest_route('starter/v1', '/reset-password', array(
        'methods' => 'POST',
        'callback' => 'starter_request_password_reset',
        'permission_callback' => function() {
            return true; // Permitir acceso público
        }
    ));
    
    // Registrar la ruta que se usa en el frontend
    register_rest_route('starter/v1', '/request-password-reset', array(
        'methods' => 'POST',
        'callback' => 'starter_request_password_reset',
        'permission_callback' => function() {
            return true; // Permitir acceso público
        }
    ));
}
add_action('rest_api_init', 'starter_register_password_reset_endpoint');

/**
 * Registrar el endpoint para validar token y cambiar contraseña
 */
function starter_register_validate_password_reset_endpoint() {
    register_rest_route('starter/v1', '/validate-password-reset', array(
        'methods' => 'POST',
        'callback' => 'starter_validate_password_reset',
        'permission_callback' => function() {
            return true; // Permitir acceso público
        }
    ));
    
    register_rest_route('starter/v1', '/complete-password-reset', array(
        'methods' => 'POST',
        'callback' => 'starter_complete_password_reset',
        'permission_callback' => function() {
            return true; // Permitir acceso público
        }
    ));
}
add_action('rest_api_init', 'starter_register_validate_password_reset_endpoint');
