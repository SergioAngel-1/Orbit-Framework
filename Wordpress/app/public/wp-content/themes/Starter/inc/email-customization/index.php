<?php
/**
 * Módulo de personalización de correos electrónicos para E-Commerce Template
 * 
 * Este módulo maneja toda la personalización de correos electrónicos del sitio,
 * incluyendo remitente, diseño, logos y plantillas HTML.
 * 
 * @package Starter
 * @subpackage EmailCustomization
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los archivos del módulo de personalización de correos
require_once dirname(__FILE__) . '/email-template.php';
require_once dirname(__FILE__) . '/email-customization.php';
require_once dirname(__FILE__) . '/welcome-email.php';
require_once dirname(__FILE__) . '/password-reset-email.php';
