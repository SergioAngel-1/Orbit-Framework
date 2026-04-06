<?php
/**
 * Popups - Archivo principal
 * 
 * Este archivo actúa como punto de entrada para la funcionalidad de popups,
 * cargando el módulo refactorizado desde la carpeta popups/.
 * 
 * La funcionalidad ha sido modularizada para mejorar la mantenibilidad y
 * organización del código, siguiendo el mismo patrón aplicado a banners
 * y otros componentes del tema.
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar el módulo de popups
require_once dirname(__FILE__) . '/popups/index.php';
