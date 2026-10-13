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

// ---------------------------------------------------------------------------
// Los nodos `product_fields_list` (persistencia `external`) los guarda su propio tipo al
// recibir `hwe_control_center_saved`: escriben metas de producto, nunca `hwe_config`.
// Nonce y capacidad ya están comprobados cuando la acción se dispara (AdminPage).
// ---------------------------------------------------------------------------
add_action(
    'hwe_control_center_saved',
    [ HWE\ControlCenter\FieldTypes\ProductFieldsListType::class, 'saveAllFromSchema' ],
    10,
    2
);

// ---------------------------------------------------------------------------
// Comando WP-CLI `wp hwe setup <archivo.json>` para seedear la config de una
// instancia nueva durante la inicialización (ver backend/scripts/setup.sh).
// ---------------------------------------------------------------------------
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'hwe setup', [ HWE\ControlCenter\Cli::class, 'setup' ] );
}
