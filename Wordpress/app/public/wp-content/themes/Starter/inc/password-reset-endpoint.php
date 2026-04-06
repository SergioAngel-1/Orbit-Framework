<?php
/**
 * Endpoint para restablecimiento de contraseña
 * 
 * Este archivo crea un endpoint personalizado para solicitar el restablecimiento
 * de contraseña, validar el token y completar el proceso de cambio de contraseña.
 * 
 * @package Starter
 */

// Prevenir acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar el módulo de restablecimiento de contraseña
require_once dirname(__FILE__) . '/password-reset/index.php';