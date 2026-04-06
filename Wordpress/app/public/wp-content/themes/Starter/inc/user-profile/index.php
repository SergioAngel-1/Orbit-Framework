<?php
/**
 * Módulo principal para el manejo del perfil de usuario
 * Este archivo carga todos los submódulos relacionados con el perfil de usuario
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Incluir las funciones de optimización si no están ya incluidas
if (!function_exists('starter_api_cache')) {
    // Usar la ruta absoluta completa en lugar de get_template_directory()
    require_once dirname(dirname(__FILE__)) . '/api-optimization.php';
}

// Cargar los submódulos
require_once dirname(__FILE__) . '/endpoints.php';
require_once dirname(__FILE__) . '/profile-data.php';
require_once dirname(__FILE__) . '/account-status.php';
require_once dirname(__FILE__) . '/cache-management.php';
require_once dirname(__FILE__) . '/avatar.php';
