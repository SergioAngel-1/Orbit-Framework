<?php
/**
 * Herramientas de Recálculo — Archivo legacy de compatibilidad
 * 
 * Este archivo ha sido refactorizado en la carpeta recalculate/:
 * - recalculate/index.php                → Loader, página principal, utilidades compartidas
 * - recalculate/recalculate-commissions.php → Comisiones de referidos
 * - recalculate/recalculate-memberships.php → FC por membresía
 * - recalculate/recalculate-checkout.php    → FC checkout invisible
 * 
 * Se mantiene este archivo como redirect para compatibilidad con includes directos.
 * 
 * @package Starter
 * @since 1.1.0
 * @deprecated 1.2.0 Usar recalculate/index.php en su lugar
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar los módulos refactorizados si no fueron cargados ya por loader.php
if (!function_exists('starter_rp_recalculate_page')) {
    require_once __DIR__ . '/recalculate/index.php';
}
