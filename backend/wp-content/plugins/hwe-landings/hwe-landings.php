<?php
/**
 * Plugin Name:  HWE Landings
 * Description:  Landings editoriales administrables desde el HWE Control Center: un registro de
 *               landings y, por cada una, un molde de slots fijos (hero por banner, entradilla,
 *               hasta 5 secciones, cita, preguntas frecuentes, productos y cierre) con URL
 *               propia, SEO y traducción a los idiomas del sitio.
 * Author:       Sergio Jauregui
 * Version:      2.0.0
 * Requires PHP: 8.0
 *
 * Es un MÓDULO del framework (`landings`): el frontend sabe si está activo por
 * `config.modules` (ver README.md y MANUAL.md de esta carpeta). Los datos viven en el grupo
 * reservado `landings` del Control Center; este plugin solo declara su esquema y sus tipos de
 * campo. Las landings con diseño propio (secciones de código) las declara la instancia con el
 * filtro `hwe_landings_static_entries`.
 */

// Sin i18n de la interfaz: el panel está en español. Las cadenas van literales a propósito —
// `__()` sin `load_plugin_textdomain()` ni carpeta `languages/` es andamiaje que promete una
// traducción que no existe. (Lo que sí se traduce es el CONTENIDO de cada landing.)

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HWE_LANDINGS_VERSION', '2.0.0' );
define( 'HWE_LANDINGS_DIR', __DIR__ );
define( 'HWE_LANDINGS_URL', plugin_dir_url( __FILE__ ) );

// Autoloader PSR-4: HWE\Landings\Foo\Bar → includes/Foo/Bar.php
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'HWE\\Landings\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = HWE_LANDINGS_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

add_action( 'plugins_loaded', array( 'HWE\\Landings\\Plugin', 'register' ) );
