<?php
/**
 * Módulo principal para la gestión de usuarios
 * Este archivo carga todos los submódulos relacionados con la gestión de usuarios
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los submódulos
require_once dirname(__FILE__) . '/register-endpoint.php';
require_once dirname(__FILE__) . '/user-approval.php';
require_once dirname(__FILE__) . '/user-rejection.php';
require_once dirname(__FILE__) . '/user-roles.php';
require_once dirname(__FILE__) . '/user-status.php';
require_once dirname(__FILE__) . '/admin-access.php';
require_once dirname(__FILE__) . '/notifications.php';
require_once dirname(__FILE__) . '/validate-unique-fields.php';
