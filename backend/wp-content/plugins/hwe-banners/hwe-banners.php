<?php
/**
 * Plugin Name:  HWE Banners
 * Plugin URI:   https://headlesswebecosystem.com
 * Description:  Sistema de banners administrables para instancias del Headless Web
 *               Ecosystem. Registra un tipo de contenido "Banner" con posiciones
 *               (placements) genéricas, slides ordenables con imagen desktop/móvil
 *               y overrides por idioma, y expone los datos vía
 *               /wp-json/hwe-banners/v1/banners para que el frontend los consuma
 *               con ISR. Genérico y reutilizable: no contiene lógica de negocio de
 *               ninguna instancia concreta.
 * Author:       Headless Web Ecosystem
 * Version:      1.0.0
 * Requires PHP: 8.0
 * Text Domain:  hwe-banners
 *
 * Complementa al HWE Control Center: desde el Control Center solo se activa o
 * desactiva el sistema de banners (config.banners.enabled); la autoría vive aquí.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HWE_BANNERS_VERSION', '1.0.0' );
define( 'HWE_BANNERS_DIR', __DIR__ );
define( 'HWE_BANNERS_URL', plugin_dir_url( __FILE__ ) );

// Autoloader PSR-4: HWE\Banners\Foo\Bar → includes/Foo/Bar.php
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'HWE\\Banners\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = HWE_BANNERS_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

HWE\Banners\Plugin::boot();
