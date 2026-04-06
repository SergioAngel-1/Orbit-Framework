<?php
/**
 * Popups - Punto de entrada
 * 
 * Este archivo actúa como punto de entrada para el módulo de popups,
 * cargando todos los componentes necesarios y registrando los hooks principales.
 * 
 * Tipos de popup soportados:
 * - membership_legacy: Popup para migración de membresía por antigüedad
 * - membership_expiration: Recordatorio de expiración de membresía (2 días antes)
 * - referral_bonus: Notificación de mensualidad por ser referido
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los componentes del módulo
require_once dirname(__FILE__) . '/helpers.php';
require_once dirname(__FILE__) . '/post-type.php';
require_once dirname(__FILE__) . '/meta-boxes.php';
require_once dirname(__FILE__) . '/admin-columns.php';
require_once dirname(__FILE__) . '/rest-api.php';

