<?php
/**
 * Roles y Permisos - Archivo principal
 * 
 * Este archivo actúa como punto de entrada para la funcionalidad de roles y permisos,
 * cargando el módulo refactorizado desde la carpeta role-permissions/.
 * 
 * @package Starter
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar el módulo de roles y permisos
require_once dirname(__FILE__) . '/role-permissions/index.php';
