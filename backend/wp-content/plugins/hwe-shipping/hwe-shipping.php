<?php
/**
 * Plugin Name:  HWE Shipping
 * Description:  Tarifas de envío por ciudad, métodos de envío (extras aditivos), envío gratis
 *               por regla y una zona de reserva de WooCommerce para el resto del país. Expuesto
 *               en /wp-json/hwe-shipping/v1. Genérico: el país base es el de la tienda.
 * Author:       Sergio Jauregui
 * Version:      2.0.0
 * Requires PHP: 8.0
 * Text Domain:  hwe-shipping
 *
 * Es un MÓDULO del framework (`shipping`): el frontend sabe si está activo por `config.modules`
 * (ver README.md y MANUAL.md de esta carpeta). Complementa al HWE Control Center: la regla de
 * envío gratis y la zona de reserva se configuran allí (pestaña «Envío» y «Tienda»); las tarifas
 * por ciudad y los métodos se gestionan aquí.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HWE_SHIPPING_VERSION', '2.0.0' );
define( 'HWE_SHIPPING_DIR', __DIR__ );

// Contrato estable con otros módulos: si esta constante existe, el envío gratis admite el filtro
// `hwe_shipping_free_reason` (ver includes/FreeShipping.php y README.md, «Hooks estables»). Un
// módulo que quiera pedir el envío gratis comprueba `defined()` y no toca las tarifas por su
// cuenta cuando `shipping` está activo.
if ( ! defined( 'HWE_SHIPPING_FREE_REASON_FILTER' ) ) {
	define( 'HWE_SHIPPING_FREE_REASON_FILTER', 'hwe_shipping_free_reason' );
}

// No toca tablas de pedidos (solo CPTs propios + el filtro de cálculo de tarifas, que no
// lee/escribe el pedido): compatible con HPOS.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Autoloader PSR-4: HWE\Shipping\Foo\Bar → includes/Foo/Bar.php
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'HWE\\Shipping\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = HWE_SHIPPING_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Al activar el plugin se crea la zona de reserva (si el Control Center la tiene encendida).
register_activation_hook( __FILE__, array( \HWE\Shipping\ReserveZone::class, 'sync' ) );

HWE\Shipping\Plugin::boot();
