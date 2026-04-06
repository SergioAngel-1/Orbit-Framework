<?php
/**
 * Banners - Archivo principal
 * 
 * Este archivo actúa como punto de entrada para la funcionalidad de banners,
 * cargando el módulo refactorizado desde la carpeta banners/.
 * 
 * La funcionalidad ha sido modularizada para mejorar la mantenibilidad y
 * organización del código, siguiendo el mismo patrón aplicado a otros
 * componentes del tema.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar el módulo de banners
require_once dirname(__FILE__) . '/banners/index.php';
