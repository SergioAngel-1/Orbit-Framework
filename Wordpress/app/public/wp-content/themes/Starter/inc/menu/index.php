<?php
/**
 * Módulo principal para la gestión de menús
 * Este archivo carga todos los submódulos relacionados con menús
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los submódulos
require_once dirname(__FILE__) . '/register.php';
require_once dirname(__FILE__) . '/product-categories.php';
require_once dirname(__FILE__) . '/menu-item-fields.php';
require_once dirname(__FILE__) . '/ajax-handlers.php';
require_once dirname(__FILE__) . '/rest-api.php';