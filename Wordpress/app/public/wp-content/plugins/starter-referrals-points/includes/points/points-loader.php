<?php
/**
 * Cargador del sistema de puntos
 * 
 * Este archivo carga todos los componentes del sistema de puntos.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar componentes del sistema de puntos
require_once dirname(__FILE__) . '/points-init.php';
require_once dirname(__FILE__) . '/points-woocommerce.php';
require_once dirname(__FILE__) . '/points-referral.php';
require_once dirname(__FILE__) . '/points-events.php';
require_once dirname(__FILE__) . '/points-checkout.php';
