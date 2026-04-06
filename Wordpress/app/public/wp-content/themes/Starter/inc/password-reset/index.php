<?php
/**
 * Módulo principal para el restablecimiento de contraseña
 * Este archivo carga todos los submódulos relacionados con el restablecimiento de contraseña
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los submódulos
require_once dirname(__FILE__) . '/db-handler.php';
require_once dirname(__FILE__) . '/endpoints.php';
require_once dirname(__FILE__) . '/request-handler.php';
require_once dirname(__FILE__) . '/validation-handler.php';
require_once dirname(__FILE__) . '/reset-handler.php';
require_once dirname(__FILE__) . '/security.php';
require_once dirname(__FILE__) . '/cors.php';

// Cargar el módulo de personalización de email (ubicado en directorio independiente)
require_once dirname(dirname(__FILE__)) . '/email-customization/index.php';
