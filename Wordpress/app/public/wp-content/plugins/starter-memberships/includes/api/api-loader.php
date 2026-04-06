<?php
/**
 * Cargador de API REST para Starter Memberships
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar archivos de API
require_once dirname(__FILE__) . '/api-init.php';
require_once dirname(__FILE__) . '/api-memberships.php';
require_once dirname(__FILE__) . '/api-products.php';
