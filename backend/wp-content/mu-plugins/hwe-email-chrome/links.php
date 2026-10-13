<?php
/**
 * Enlaces de los correos al frontend headless (no a WordPress), con el prefijo del idioma.
 *
 * Parte de `hwe-email-chrome.php`, que lo carga: las funciones son globales y se reparten por
 * tema para que cada archivo se lea de una vez.
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Enlaces al frontend
// ---------------------------------------------------------------------------

/**
 * Origen público del frontend, sin barra final.
 *
 * Orden: HEADLESS_FRONTEND_URL → `brand.url` del Control Center → primer origen de la
 * allowlist headless → home_url(). `brand.url` va antes que la allowlist porque ésta cae
 * a http://localhost:3000 cuando el proceso no define las constantes (p. ej. el cron, que
 * también manda correos: cancelaciones automáticas, Action Scheduler).
 */
function hwe_email_frontend_base(): string {
	$base = '';
	if ( defined( 'HEADLESS_FRONTEND_URL' ) && HEADLESS_FRONTEND_URL ) {
		$base = (string) HEADLESS_FRONTEND_URL;
	} elseif ( class_exists( 'HWE\\ControlCenter\\Storage' ) && '' !== (string) HWE\ControlCenter\Storage::get( array( 'brand', 'url' ) ) ) {
		$base = (string) HWE\ControlCenter\Storage::get( array( 'brand', 'url' ) );
	} elseif ( function_exists( 'hwe_allowed_origins' ) ) {
		$base = (string) ( hwe_allowed_origins()[0] ?? '' );
	}
	if ( '' === $base ) {
		$base = (string) home_url();
	}
	return rtrim( $base, '/' );
}

/**
 * URL absoluta del frontend para un enlace de correo: `$route` es la ruta del starter
 * (`/account`, `/reset-password`, `/account/orders/42`), y el idioma, si no es el de la
 * tienda, va de prefijo (`/en/account`).
 *
 * @param array<string,string> $query Parámetros (se codifican aquí).
 */
function hwe_email_frontend_url( string $route, array $query = array(), ?string $locale = null ): string {
	$locale = hwe_email_resolve_locale( $locale );
	$prefix = strtolower( $locale ) === strtolower( hwe_email_default_locale() ) ? '' : '/' . $locale;
	$path   = '/' . ltrim( $route, '/' );
	$url    = hwe_email_frontend_base() . $prefix . ( '/' === $path ? ( '' === $prefix ? '/' : '' ) : $path );
	return $query ? add_query_arg( array_map( 'rawurlencode', $query ), $url ) : $url;
}
