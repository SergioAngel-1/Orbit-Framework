<?php
/**
 * Cargador del sistema de referidos
 * 
 * Este archivo carga todos los componentes del sistema de referidos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar componentes del sistema de referidos
require_once dirname(__FILE__) . '/referral-init.php';
require_once dirname(__FILE__) . '/referral-codes.php';
require_once dirname(__FILE__) . '/referral-relationships.php';
require_once dirname(__FILE__) . '/referral-tracking.php';
require_once dirname(__FILE__) . '/referral-api.php';
require_once dirname(__FILE__) . '/referral-shortcodes.php';
