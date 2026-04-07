<?php
/**
 * Pago con Tarjeta en Checkout — Orquestador
 * 
 * Cuando un usuario paga con tarjeta en el checkout:
 * 1. Se cobra el monto + 5% via Wompi
 * 2. Se crea una transacción de "compra de FC" por el monto total
 * 3. Se crea una transacción de "pago con FC" para el pedido
 * 
 * Sub-módulos:
 * - card-payment/db.php         → Tabla y helpers de base de datos
 * - card-payment/endpoints.php  → Registro de rutas REST y handlers
 * - card-payment/processor.php  → Lógica central de FC y hooks de webhook
 * 
 * @package Starter
 * @since 1.0.0 (refactorizado en 1.1.0)
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Constante para el porcentaje de incremento por pago con tarjeta
define('STARTER_CARD_PAYMENT_FEE_PERCENTAGE', 5);

// Cargar sub-módulos en orden de dependencia
require_once __DIR__ . '/card-payment/db.php';         // DB helpers (sin dependencias)
require_once __DIR__ . '/card-payment/processor.php';  // Core FC logic + webhook hooks (depende de db.php)
require_once __DIR__ . '/card-payment/endpoints.php';  // REST endpoints (depende de db.php + processor.php)
