<?php
/**
 * Gestión de usuarios
 * 
 * Este archivo contiene todas las funciones relacionadas con el registro,
 * aprobación y rechazo de usuarios en el sistema.
 * 
 * @package Starter
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar el módulo de gestión de usuarios
require_once dirname(__FILE__) . '/user-management/index.php';
