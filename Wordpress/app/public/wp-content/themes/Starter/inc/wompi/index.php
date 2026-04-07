<?php
/**
 * Wompi Payment Gateway Module
 * 
 * @package Starter
 * @since 1.0.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar endpoint de Wompi
require_once __DIR__ . '/wompi-endpoint.php';

// Cargar tipo de producto Virtual Coins
require_once __DIR__ . '/virtual-coins-product-type.php';

// Cargar compras de membresías con Wompi
require_once __DIR__ . '/membership-purchase.php';

// Cargar pago con tarjeta en checkout (transacciones invisibles de FC)
require_once __DIR__ . '/checkout-card-payment.php';

// Cargar transacciones invisibles de FC para TODOS los pedidos (cualquier método de pago)
require_once __DIR__ . '/order-fc-transactions.php';

// Cargar historial de transacciones Wompi (admin)
require_once __DIR__ . '/wompi-history.php';

// Cargar cron de limpieza de registros pendientes abandonados
require_once __DIR__ . '/pending-cleanup.php';
