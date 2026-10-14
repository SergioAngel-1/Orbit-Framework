<?php
/**
 * Plugin Name:  HWE External Integration
 * Description:  Acceso de un integrador externo (ERP, logística) a los pedidos por GraphQL: rol
 *               mínimo `hwe_integration`, Application Password válida solo desde las IPs
 *               autorizadas y solo en GraphQL, sin inicio de sesión interactivo, y una política
 *               cerrada de lo que puede pedir y cambiar.
 * Author:       Sergio Jauregui
 * Version:      2.0.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 *
 * Es un MÓDULO del framework (`external-integration`). Reparte el trabajo así:
 *   · la PUERTA DE RED (qué IP llega a GraphQL) vive en `mu-plugins/hwe-bff-only.php`; este
 *     plugin la enciende (`hwe_integration_gate_enabled`): sin el módulo, una IP de
 *     `HWE_INTEGRATION_ALLOWED_IPS` no abre nada;
 *   · QUIÉN es el usuario de servicio y cómo se autentica: `Role` y `Access`;
 *   · QUÉ puede pedir y cambiar quien llega: `Policy` y `GraphqlGuard`
 *     (filtro `hwe_integration_policy`).
 *
 * El usuario se crea a mano (WP-CLI): ver MANUAL.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HWE_EXTERNAL_INTEGRATION_VERSION', '2.0.0' );
define( 'HWE_EXTERNAL_INTEGRATION_DIR', __DIR__ );

// No toca tablas de pedidos por su cuenta: usa la API de WooCommerce (compatible con HPOS).
add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Autoloader PSR-4: HWE\ExternalIntegration\Foo → includes/Foo.php
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'HWE\\ExternalIntegration\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = HWE_EXTERNAL_INTEGRATION_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

// La puerta se enciende al cargar el archivo: ver `Plugin::boot()` (en `plugins_loaded` llega tarde).
\HWE\ExternalIntegration\Plugin::boot();

add_action( 'plugins_loaded', array( 'HWE\\ExternalIntegration\\Plugin', 'register' ) );
