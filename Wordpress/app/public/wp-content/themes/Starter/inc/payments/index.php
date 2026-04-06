<?php
/**
 * Payment Gateway Loader
 * 
 * Carga la interfaz de pasarela y la implementación activa basándose
 * en la configuración de Site Settings (payment_gateway).
 * 
 * La pasarela activa se expone via starter_get_payment_gateway()
 * para que cualquier módulo del tema pueda usarla.
 * 
 * Los módulos existentes en inc/wompi/ se siguen cargando directamente
 * (contienen endpoints REST, DB, processors, etc. específicos de Wompi).
 * Esta capa de abstracción permite que en el futuro se intercambien
 * pasarelas sin modificar la lógica de negocio.
 * 
 * @package Starter
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Cargar interfaz
require_once __DIR__ . '/interface-gateway.php';

// Cargar implementaciones disponibles
require_once __DIR__ . '/class-wompi-gateway.php';

/**
 * Registro de pasarelas disponibles
 * Agregar nuevas pasarelas aquí al implementarlas.
 */
function starter_get_gateway_registry(): array {
    return [
        'wompi' => 'Starter_Wompi_Gateway',
        // 'stripe'      => 'Starter_Stripe_Gateway',      // Futuro
        // 'mercadopago' => 'Starter_MercadoPago_Gateway',  // Futuro
    ];
}

/**
 * Obtener la instancia de la pasarela de pago activa
 * 
 * Lee 'payment_gateway' de Site Settings. Si no está configurada
 * o la pasarela no existe en el registry, usa Wompi como fallback.
 * 
 * @return Starter_Payment_Gateway
 */
function starter_get_payment_gateway(): Starter_Payment_Gateway {
    static $instance = null;

    if ($instance !== null) {
        return $instance;
    }

    $gateway_id = function_exists('site_get_option')
        ? site_get_option('payment_gateway', 'wompi')
        : 'wompi';

    $registry = starter_get_gateway_registry();

    if (isset($registry[$gateway_id]) && class_exists($registry[$gateway_id])) {
        $class = $registry[$gateway_id];
        $instance = new $class();
    } else {
        // Fallback a Wompi
        $instance = new Starter_Wompi_Gateway();
    }

    return $instance;
}

/**
 * Obtener lista de pasarelas disponibles (para admin UI)
 * 
 * @return array [ ['id' => 'wompi', 'name' => 'Wompi'], ... ]
 */
function starter_get_available_gateways(): array {
    $registry = starter_get_gateway_registry();
    $gateways = [];

    foreach ($registry as $id => $class) {
        if (class_exists($class)) {
            $gw = new $class();
            $gateways[] = [
                'id'          => $gw->get_id(),
                'name'        => $gw->get_name(),
                'configured'  => $gw->is_configured(),
            ];
        }
    }

    return $gateways;
}
