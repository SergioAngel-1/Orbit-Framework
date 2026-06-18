<?php
/**
 * Plugin Name: WooCommerce Headless Bridge
 * Description: Ajustes de WooCommerce para operación headless detrás del BFF de
 *              Next.js: desactiva el nonce de la Store API (el BFF ya impone
 *              verificación de Origin + CSRF) y mantiene CORS coherente.
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 *
 * Parte de la Fase 3 (proxy inverso a WooCommerce) del plan de producción.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1) DESACTIVAR EL NONCE DE LA STORE API
 *    La Store API exige un nonce para mutaciones de carrito/checkout como
 *    protección CSRF pensada para llamadas desde el navegador. En este montaje
 *    headless, SOLO el BFF (servidor Next, red interna) llama a la Store API, y
 *    el BFF ya verifica el Origin (+ CSRF en Fase 4). Por eso es seguro y
 *    necesario desactivar el nonce: la autenticación del carrito se hace con el
 *    `Cart-Token`.
 *
 *    Requiere WooCommerce Blocks (incluido en WooCommerce moderno).
 * ---------------------------------------------------------------------- */
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

/* -------------------------------------------------------------------------
 * 2) CORS PARA LA STORE API (defensa en profundidad)
 *    Aunque el BFF llama servidor-a-servidor (sin CORS de navegador), si en
 *    algún flujo se llamara desde el cliente, restringimos el origen a la
 *    allowlist definida en headless-config.php.
 * ---------------------------------------------------------------------- */
add_filter(
	'rest_pre_serve_request',
	function ( $served ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( false === strpos( (string) $uri, '/wc/store/' ) ) {
			return $served;
		}

		$request = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		// Reutiliza el comparador de orígenes definido en headless-config.php.
		if ( function_exists( 'hwe_match_origin' ) ) {
			$allowed = hwe_match_origin( $request );
			if ( null !== $allowed ) {
				header( 'Access-Control-Allow-Origin: ' . $allowed );
				header( 'Access-Control-Allow-Credentials: true' );
				header( 'Access-Control-Expose-Headers: Cart-Token, Nonce' );
				header( 'Vary: Origin' );
			}
		}

		return $served;
	}
);
