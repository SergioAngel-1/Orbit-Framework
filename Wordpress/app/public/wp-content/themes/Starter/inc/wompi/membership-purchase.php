<?php
/**
 * Compras de Membresías con Wompi — Orquestador
 * 
 * Este módulo maneja:
 * - Registro de compras pendientes de membresías
 * - Procesamiento de pagos aprobados (asignación de membresía + Virtual Coins)
 * - Creación de órdenes WC para trazabilidad
 * - Consulta de estado de compras
 * 
 * Sub-módulos:
 * - membership-purchase/db.php         → Tabla y helpers de base de datos
 * - membership-purchase/endpoints.php  → Registro de rutas REST y handlers
 * - membership-purchase/processor.php  → Lógica central de activación y hooks de webhook
 * 
 * @package Starter
 * @since 1.0.0 (refactorizado en 1.1.0)
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar sub-módulos en orden de dependencia
require_once __DIR__ . '/membership-purchase/db.php';         // DB helpers (sin dependencias)
require_once __DIR__ . '/membership-purchase/processor.php';  // Core logic + webhook hooks (depende de db.php)
require_once __DIR__ . '/membership-purchase/endpoints.php';  // REST endpoints (depende de db.php + processor.php)
