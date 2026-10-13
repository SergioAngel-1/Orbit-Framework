<?php
/**
 * Plugin Name: HWE Menu Revalidate
 * Description: Dispara la revalidación ISR del tag `menus` en el frontend
 *              Next.js al editar un menú de navegación o sus ítems. Misma
 *              mecánica que `hwe-post-revalidate.php` con `posts`.
 *
 *              Sin esto el tag `menus` era inalcanzable: las consultas de
 *              `lib/navigation/menu.ts` lo declaran (`tags: ["menus"]`), pero
 *              nadie lo disparaba nunca, así que un cambio en un menú solo se
 *              veía al caducar la ventana ISR.
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispara una sola vez por petición.
 *
 * Guardar un menú en Apariencia → Menús emite `wp_update_nav_menu_item` por
 * CADA ítem y además `wp_update_nav_menu` al cerrar. Sin esta guarda, un guardado normal lanzaría
 * decenas de POST a `/api/revalidate`, que tiene rate-limit por IP: los
 * primeros pasarían y el resto recibiría 429, con el agravante de que WordPress
 * los manda en modo `blocking => false` y nadie vería el error.
 */
function hwe_menu_revalidate_once(): void {
	static $ya_disparado = false;

	if ( $ya_disparado ) {
		return;
	}
	if ( ! class_exists( 'HWE\ControlCenter\Revalidation' ) ) {
		return;
	}

	$ya_disparado = true;
	\HWE\ControlCenter\Revalidation::trigger( 'menus' );
}

// Alta/edición de un ítem y guardado del menú completo. `wp_delete_nav_menu`
// cubre el borrado, que también cambia lo que el frontend debe servir.
add_action( 'wp_update_nav_menu', 'hwe_menu_revalidate_once' );
add_action( 'wp_update_nav_menu_item', 'hwe_menu_revalidate_once' );
add_action( 'wp_delete_nav_menu', 'hwe_menu_revalidate_once' );
