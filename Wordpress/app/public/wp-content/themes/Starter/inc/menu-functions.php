<?php
/**
 * Menú - Funciones para gestionar menús desde WordPress
 * Este archivo carga el módulo principal de menús
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar el módulo principal de menús
require_once dirname(__FILE__) . '/menu/index.php';
