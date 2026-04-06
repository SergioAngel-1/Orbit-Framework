<?php
/**
 * Ventas Especiales - Punto de entrada
 * 
 * Este archivo actúa como punto de entrada para el módulo de Ventas Especiales,
 * cargando todos los componentes necesarios.
 * 
 * Funcionalidades:
 * - Oculta pedidos de membresías y paquetes de FC de la lista principal de WooCommerce
 * - Página de administración dedicada bajo WooCommerce > Ventas Especiales
 * - Filtros por tipo (membresía/FC), estado, búsqueda por referencia/cliente
 * - Resumen con estadísticas desde las tablas personalizadas de compras
 * 
 * Los pedidos se identifican por el meta '_order_type' con valores:
 * - 'membership_purchase'    → Compra de membresía vía Wompi
 * - 'virtual_coins_purchase'  → Compra de paquete de Virtual Coins vía Wompi
 * 
 * @package Starter\SpecialOrders
 * @since 1.6.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar los componentes del módulo
require_once dirname(__FILE__) . '/constants.php';
require_once dirname(__FILE__) . '/hide-from-wc-orders.php';
require_once dirname(__FILE__) . '/queries.php';
require_once dirname(__FILE__) . '/render.php';
require_once dirname(__FILE__) . '/styles.php';
require_once dirname(__FILE__) . '/ajax-handlers.php';
require_once dirname(__FILE__) . '/admin-page.php';
