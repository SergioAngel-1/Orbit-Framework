<?php
/**
 * Roles y Permisos - Punto de entrada
 * 
 * Este archivo actúa como punto de entrada para el módulo de gestión de roles y permisos,
 * cargando todos los componentes necesarios y registrando los hooks principales.
 * 
 * @package Starter
 * @version 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los componentes del módulo
require_once dirname(__FILE__) . '/menu.php';
require_once dirname(__FILE__) . '/pages/list-roles.php';
require_once dirname(__FILE__) . '/pages/create-role.php';
require_once dirname(__FILE__) . '/pages/edit-role.php';
require_once dirname(__FILE__) . '/pages/configure-sidebar.php';
require_once dirname(__FILE__) . '/apply-permissions.php';

