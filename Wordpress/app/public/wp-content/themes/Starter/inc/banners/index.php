<?php
/**
 * Banners - Punto de entrada
 * 
 * Este archivo actúa como punto de entrada para el módulo de banners,
 * cargando todos los componentes necesarios y registrando los hooks principales.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los componentes del módulo
require_once dirname(__FILE__) . '/helpers.php';
require_once dirname(__FILE__) . '/post-type.php';
require_once dirname(__FILE__) . '/meta-boxes/index.php';
require_once dirname(__FILE__) . '/admin-columns.php';
require_once dirname(__FILE__) . '/rest-api.php';

// Registrar la activación del módulo
