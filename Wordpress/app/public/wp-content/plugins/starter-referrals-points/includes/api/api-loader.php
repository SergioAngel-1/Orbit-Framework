<?php
/**
 * Cargador de API REST
 * 
 * Este archivo carga todos los componentes de la API REST.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar componentes de la API REST
require_once dirname(__FILE__) . '/api-init.php';
require_once dirname(__FILE__) . '/api-points.php';
require_once dirname(__FILE__) . '/api-referrals.php';
require_once dirname(__FILE__) . '/api-system.php';
require_once dirname(__FILE__) . '/api-admin.php';
require_once dirname(__FILE__) . '/api-newsletter.php';
require_once dirname(__FILE__) . '/../referrals/referral-api.php';

// Registrar endpoints
starter_rp_register_api_endpoints();
