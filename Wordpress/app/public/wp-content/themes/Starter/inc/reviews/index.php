<?php
/**
 * Reviews Module - Punto de entrada
 * 
 * Sistema de reseñas de productos con datos enriquecidos,
 * verificación de comprador, respuestas, calificación de pedidos
 * e integración con Virtual Coins.
 * 
 * @package Starter
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar los componentes del módulo
require_once dirname(__FILE__) . '/helpers.php';
require_once dirname(__FILE__) . '/rest-api.php';
require_once dirname(__FILE__) . '/order-rating.php';
require_once dirname(__FILE__) . '/reviews-listing.php';
require_once dirname(__FILE__) . '/admin-menu.php';
