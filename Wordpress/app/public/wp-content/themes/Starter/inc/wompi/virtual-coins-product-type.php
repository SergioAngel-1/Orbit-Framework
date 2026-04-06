<?php
/**
 * Tipo de Producto "Virtual Coins" para WooCommerce — Orquestador
 * 
 * Permite crear productos virtuales que representan paquetes de Virtual Coins
 * para compra directa a través de la pasarela Wompi.
 * 
 * Sub-módulos:
 * - virtual-coins/product-type.php  → Definición WC: categoría, helpers, admin UI
 * - virtual-coins/db.php            → Tabla y helpers de base de datos
 * - virtual-coins/endpoints.php     → Registro de rutas REST y handlers
 * - virtual-coins/processor.php     → Lógica central de acreditación y hooks de webhook
 * 
 * @package Starter
 * @since 1.0.0 (refactorizado en 1.1.0)
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar sub-módulos en orden de dependencia
require_once __DIR__ . '/virtual-coins/product-type.php';  // WC product type: helpers, admin UI (sin dependencias)
require_once __DIR__ . '/virtual-coins/db.php';             // DB helpers (sin dependencias)
require_once __DIR__ . '/virtual-coins/processor.php';      // Core FC credit logic + webhook hooks (depende de db.php)
require_once __DIR__ . '/virtual-coins/endpoints.php';      // REST endpoints (depende de product-type.php + db.php + processor.php)
