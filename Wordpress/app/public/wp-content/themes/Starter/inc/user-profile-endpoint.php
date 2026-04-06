<?php
/**
 * Funciones para el manejo del perfil de usuario
 * 
 * Optimizadas para un mejor rendimiento en integración con React
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar el módulo de perfil de usuario
require_once dirname(__FILE__) . '/user-profile/index.php';
