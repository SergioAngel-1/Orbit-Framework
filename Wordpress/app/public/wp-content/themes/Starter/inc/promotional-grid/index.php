<?php
/**
 * Módulo principal para el manejo de Grillas Publicitarias
 * 
 * Sistema unificado de administración por niveles de membresía.
 * El sistema CPT legacy ha sido deprecado en favor de la gestión centralizada.
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar REST API y funciones helper (necesarias para el frontend)
require_once dirname(__FILE__) . '/rest-api.php';
require_once dirname(__FILE__) . '/helper-functions.php';

// Cargar sistema de administración por membresía
require_once dirname(__FILE__) . '/admin/menu.php';
