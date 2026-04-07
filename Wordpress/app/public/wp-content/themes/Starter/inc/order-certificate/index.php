<?php
/**
 * Order Certificate Module
 * 
 * Módulo para generación de certificados PDF de retribución de cosecha colectiva
 * 
 * @package Starter
 * @since 1.0.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar la clase generadora de certificados
require_once __DIR__ . '/class-order-certificate-pdf.php';
