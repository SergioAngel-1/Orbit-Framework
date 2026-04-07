<?php
/**
 * Funciones para la grilla publicitaria de productos
 * 
 * Este archivo carga el módulo principal de grillas publicitarias
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar el módulo de grillas publicitarias
require_once dirname(__FILE__) . '/promotional-grid/index.php';
