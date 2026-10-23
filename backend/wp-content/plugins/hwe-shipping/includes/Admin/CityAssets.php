<?php

namespace HWE\Shipping\Admin;

use HWE\Shipping\PostTypes;

/**
 * Encola el JS y el CSS del editor de ciudad (repetidor de opciones, campos según el tipo).
 * Solo en la pantalla de edición del CPT de tarifas.
 */
class CityAssets {

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== PostTypes::RATE_SLUG || $screen->base !== 'post' ) {
			return;
		}

		$main = HWE_SHIPPING_DIR . '/hwe-shipping.php';
		$url  = plugins_url( 'assets', $main );

		wp_enqueue_style( 'hwe-shipping-city', $url . '/city-editor.css', [], self::version( 'assets/city-editor.css' ) );
		// `wp-api-fetch` pone la raíz y el nonce de `wp_rest`: «Validar» llama a la ruta del panel.
		wp_enqueue_script( 'hwe-shipping-city', $url . '/city-editor.js', [ 'wp-api-fetch' ], self::version( 'assets/city-editor.js' ), true );
	}

	/** Versión para cache-busting: la del plugin más la fecha del archivo. */
	private static function version( string $relPath ): string {
		$file = HWE_SHIPPING_DIR . '/' . $relPath;
		$base = defined( 'HWE_SHIPPING_VERSION' ) ? HWE_SHIPPING_VERSION : '2.0.0';
		$time = is_readable( $file ) ? filemtime( $file ) : false;
		return $time ? $base . '.' . $time : $base;
	}
}
