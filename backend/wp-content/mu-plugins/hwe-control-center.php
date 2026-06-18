<?php
/**
 * Plugin Name:  HWE Control Center
 * Plugin URI:   https://headlesswebecosystem.com
 * Description:  Panel de configuración central para la plantilla Headless Web
 *               Ecosystem. Gestiona marca, diseño (design tokens), e-commerce,
 *               pasarelas de pago e integraciones desde un único panel en
 *               wp-admin. Expone la configuración pública (sin secretos) vía
 *               /wp-json/hwe/v1/config para que el frontend la consuma con ISR.
 * Author:       Headless Web Ecosystem
 * Version:      1.0.0
 *
 * Al ser mu-plugin se carga automáticamente; no puede desactivarse desde el panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Auto-cargador PSR-4 para el namespace HWE\ControlCenter
// Mapeo: HWE\ControlCenter\Foo\Bar → hwe-control-center/Foo/Bar.php
// ---------------------------------------------------------------------------
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'HWE\\ControlCenter\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = __DIR__ . '/hwe-control-center/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

// ---------------------------------------------------------------------------
// Inicializar subsistemas tras cargar todos los plugins
// ---------------------------------------------------------------------------
add_action( 'init', static function (): void {
	HWE\ControlCenter\RestApi::register();
	HWE\ControlCenter\AdminPage::register();
}, 20 );
