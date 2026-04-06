<?php
/**
 * Historial de Transacciones Wompi — Orquestador
 * 
 * Página de administración que muestra un historial unificado de todas
 * las transacciones procesadas por Wompi:
 * - Compras de membresías (MB-)
 * - Compras de Virtual Coins (FC-)
 * - Pagos con tarjeta en checkout (CPY-)
 * 
 * Sub-módulos:
 * - wompi-history/query.php     → Consultas UNION ALL paginadas + resumen
 * - wompi-history/wompi-api.php → Consultas a la API de Wompi con caché
 * - wompi-history/page.php      → Menú admin + renderizado de la página
 * 
 * @package Starter
 * @since 1.0.0 (refactorizado en 1.1.0)
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar sub-módulos en orden de dependencia
require_once __DIR__ . '/wompi-history/query.php';     // Data layer (sin dependencias)
require_once __DIR__ . '/wompi-history/wompi-api.php'; // Wompi API calls (sin dependencias)
require_once __DIR__ . '/wompi-history/page.php';      // Admin UI (depende de query.php + wompi-api.php)
