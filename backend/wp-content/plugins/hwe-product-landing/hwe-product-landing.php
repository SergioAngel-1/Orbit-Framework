<?php
/**
 * Plugin Name:  HWE Product Landing
 * Description:  Landing de aprendizaje por producto: un metabox en la ficha del producto con
 *               secciones (hero, educativa, sobre el producto + componentes, cualidades,
 *               consejos, CTA, video, cierre) y listas propias, expuesto en WPGraphQL como
 *               `Product.landing`. Textos traducibles a los idiomas secundarios del sitio.
 * Author:       Sergio Jauregui
 * Version:      2.0.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 *
 * Es un MÓDULO del framework (`product-landing`): el frontend sabe si está activo por
 * `config.modules` (ver README.md y MANUAL.md de esta carpeta). La publicación y el contenido
 * se gestionan desde la ficha de cada producto.
 */

// Sin i18n de la interfaz: el panel está en español. Las cadenas van literales a propósito —
// `__()` sin `load_plugin_textdomain()` ni carpeta `languages/` es andamiaje que promete una
// traducción que no existe. (Lo que sí se traduce es el CONTENIDO de la landing: ver `Translations`.)

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HWE_PRODUCT_LANDING_VERSION', '2.0.0' );
define( 'HWE_PRODUCT_LANDING_DIR', __DIR__ );
define( 'HWE_PRODUCT_LANDING_URL', plugin_dir_url( __FILE__ ) );

// No toca tablas de pedidos (solo post meta de producto): compatible con HPOS.
add_action( 'before_woocommerce_init', static function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// Autoloader PSR-4: HWE\ProductLanding\Foo\Bar → includes/Foo/Bar.php
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'HWE\\ProductLanding\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = HWE_PRODUCT_LANDING_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

add_action( 'plugins_loaded', [ 'HWE\\ProductLanding\\Plugin', 'register' ] );
