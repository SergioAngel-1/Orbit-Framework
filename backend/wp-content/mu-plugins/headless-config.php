<?php
/**
 * Plugin Name: Headless Configuration
 * Description: Convierte esta instalación de WordPress en un CMS exclusivamente Headless:
 *              bloquea el frontend nativo, redirige el tráfico humano a /wp-admin,
 *              mantiene viva la API de WPGraphQL y aplica cabeceras CORS al endpoint.
 * Author:      Headless Web Ecosystem
 * Version:     2.0.0
 *
 * Al ser un "must-use plugin" (mu-plugin) se carga automáticamente y no puede
 * desactivarse desde el panel, lo que garantiza el comportamiento headless.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Acceso directo no permitido.
}

/**
 * Devuelve la lista de orígenes autorizados para CORS.
 *
 * Prioridad:
 *   1. Constante HEADLESS_ALLOWED_ORIGINS (lista separada por comas) -> producción.
 *   2. Constante HEADLESS_FRONTEND_URL (origen único)               -> desarrollo.
 *   3. http://localhost:3000                                        -> fallback.
 *
 * @return string[] Orígenes normalizados (sin barra final).
 */
function hwe_allowed_origins(): array {
	$origins = array();

	if ( defined( 'HEADLESS_ALLOWED_ORIGINS' ) && HEADLESS_ALLOWED_ORIGINS ) {
		$origins = array_map( 'trim', explode( ',', HEADLESS_ALLOWED_ORIGINS ) );
	} elseif ( defined( 'HEADLESS_FRONTEND_URL' ) && HEADLESS_FRONTEND_URL ) {
		$origins = array( HEADLESS_FRONTEND_URL );
	} else {
		$origins = array( 'http://localhost:3000' );
	}

	// Normaliza (sin barra final) y descarta vacíos.
	$origins = array_filter( array_map(
		static function ( $o ) {
			return rtrim( (string) $o, '/' );
		},
		$origins
	) );

	return array_values( array_unique( $origins ) );
}

/**
 * Comprueba si un origen está en la allowlist. Devuelve el origen normalizado
 * (apto para la cabecera Access-Control-Allow-Origin) o null si no está permitido.
 */
function hwe_match_origin( string $request_origin ): ?string {
	$request_origin = rtrim( $request_origin, '/' );
	if ( '' === $request_origin ) {
		return null;
	}
	return in_array( $request_origin, hwe_allowed_origins(), true ) ? $request_origin : null;
}

/**
 * ¿La petición trae el secreto interno del BFF (server-to-server, cabecera
 * X-HWE-Internal-Secret) con comparación en tiempo constante? Compartido por
 * las APIs internas del Control Center (`PaymentsInternalApi`,
 * `IntegrationsInternalApi`): sin él, responden 401 sin cuerpo útil.
 */
function hwe_has_internal_secret( WP_REST_Request $request ): bool {
	if ( ! defined( 'HWE_REVALIDATION_SECRET' ) || ! HWE_REVALIDATION_SECRET ) {
		return false;
	}
	$provided = $request->get_header( 'x_hwe_internal_secret' );
	if ( ! is_string( $provided ) || '' === $provided ) {
		return false;
	}
	return hash_equals( (string) HWE_REVALIDATION_SECRET, $provided );
}

/**
 * Determina si la petición actual va dirigida al endpoint de GraphQL.
 */
function hwe_is_graphql_request(): bool {
	if ( function_exists( 'is_graphql_http_request' ) && is_graphql_http_request() ) {
		return true;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
	// Coincidencia por segmento de path (path === /graphql o empieza por
	// /graphql/), no por subcadena: "strpos($uri, '/graphql')" daba falso
	// positivo con cualquier URL cuyo path contuviera esa subcadena en
	// cualquier posición (p. ej. una entrada de blog en /blog/graphql-vs-rest).
	return (bool) preg_match( '#^/graphql(?:/|$)#', $path );
}

/* -------------------------------------------------------------------------
 * 1) BLOQUEO DEL FRONTEND NATIVO
 *    Cualquier visita pública (que no sea admin, login, cron, REST, GraphQL,
 *    sitemap o una petición AJAX) se redirige al panel de administración.
 * ---------------------------------------------------------------------- */
add_action(
	'template_redirect',
	function () {
		// Permitimos peticiones internas / de sistema.
		if (
			is_admin()
			|| hwe_is_graphql_request()
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			return;
		}

		// Permitimos las pantallas de login/registro.
		$self = isset( $_SERVER['PHP_SELF'] ) ? basename( wp_unslash( $_SERVER['PHP_SELF'] ) ) : '';
		if ( in_array( $self, array( 'wp-login.php', 'wp-register.php' ), true ) ) {
			return;
		}

		// El resto del frontend nativo queda bloqueado -> al panel.
		wp_safe_redirect( admin_url(), 302 );
		exit;
	},
	0
);

/* -------------------------------------------------------------------------
 * 2) LIMPIEZA DE LA CABECERA <head> / DESACTIVAR RUIDO DEL FRONTEND
 * ---------------------------------------------------------------------- */
add_action(
	'init',
	function () {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'feed_links_extra', 3 );

		// Desactiva el feed XML-RPC (superficie de ataque innecesaria en headless).
		add_filter( 'xmlrpc_enabled', '__return_false' );
	}
);

/* -------------------------------------------------------------------------
 * 3) CORS PARA EL ENDPOINT DE GRAPHQL
 *    WPGraphQL expone filtros para añadir/ajustar cabeceras de respuesta.
 * ---------------------------------------------------------------------- */
add_filter(
	'graphql_response_headers_to_send',
	function ( $headers ) {
		$request = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$allowed = hwe_match_origin( $request );

		// Solo emitimos cabeceras CORS si el origen está en la allowlist.
		// Un origen no autorizado NO recibe Access-Control-Allow-Origin (denegado).
		if ( null !== $allowed ) {
			$headers['Access-Control-Allow-Origin']      = $allowed;
			$headers['Access-Control-Allow-Credentials'] = 'true';
			$headers['Access-Control-Allow-Headers']     = 'Authorization, Content-Type, X-JWT-Auth, X-JWT-Refresh, X-CSRF-Token';
			$headers['Access-Control-Allow-Methods']     = 'GET, POST, OPTIONS';
		}

		// Siempre variamos por Origin para no envenenar cachés intermedias.
		$headers['Vary'] = 'Origin';

		return $headers;
	}
);

/**
 * Responde de inmediato a las peticiones preflight (OPTIONS) del endpoint GraphQL,
 * pero únicamente si el origen está autorizado.
 */
add_action(
	'init',
	function () {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'OPTIONS' !== $method || ! hwe_is_graphql_request() ) {
			return;
		}

		$request = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$allowed = hwe_match_origin( $request );

		header( 'Vary: Origin' );

		if ( null === $allowed ) {
			// Preflight desde un origen no permitido -> rechazado.
			status_header( 403 );
			exit;
		}

		header( 'Access-Control-Allow-Origin: ' . $allowed );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-JWT-Auth, X-JWT-Refresh, X-CSRF-Token' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Max-Age: 600' );
		status_header( 204 );
		exit;
	},
	1
);

/* -------------------------------------------------------------------------
 * 4) DESACTIVAR EL EDITOR DE ARCHIVOS DEL PANEL (buena práctica de seguridad)
 * ---------------------------------------------------------------------- */
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

/* -------------------------------------------------------------------------
 * 5) LA API REST DEL PANEL, EN EL MISMO ORIGEN QUE EL PANEL
 *
 * `get_rest_url()` se construye sobre `home_url()`, NO sobre `site_url()`. En
 * esta arquitectura son distintos a propósito cuando el panel vive en su propio
 * host:
 *
 *     siteurl = https://admin.midominio.com   (el panel)
 *     home    = https://midominio.com         (el frontend Next.js)
 *
 * El JavaScript de wp-admin recibía entonces `https://midominio.com/wp-json/`
 * y llamaba a la API REST de OTRO ORIGEN: preflight en cada llamada y, como la
 * cookie de sesión está acotada al host del panel, 403 en todas, con
 * componentes (la medioteca, p. ej.) que reintentaban en bucle y ocupaban los
 * workers de Apache.
 *
 * Se devuelven las URLs REST al host del panel cuando la petición la sirve ese
 * host. Acotado: servida en el dominio público, se deja como está; con un único
 * host (dominio único o desarrollo) no se toca nada; el BFF no pasa por aquí
 * (llama por la red interna con URLs explícitas, no con `rest_url()`).
 * ---------------------------------------------------------------------- */
function hwe_rest_url_on_admin_host( $url ) {
	$request_host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
	if ( '' === $request_host || ! is_string( $url ) ) {
		return $url; // WP-CLI o cron interno: no hay host que respetar.
	}

	$admin_host = wp_parse_url( site_url(), PHP_URL_HOST );
	$home_host  = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! $admin_host || ! $home_host || $admin_host === $home_host ) {
		return $url;
	}

	// El puerto puede venir pegado en HTTP_HOST: se compara solo el host.
	$request_host = strtolower( (string) strtok( $request_host, ':' ) );
	if ( strtolower( $admin_host ) !== $request_host ) {
		return $url;
	}

	return str_replace( '//' . $home_host, '//' . $admin_host, $url );
}
add_filter( 'rest_url', 'hwe_rest_url_on_admin_host', 10, 1 );

/* -------------------------------------------------------------------------
 * 6) LAS ACCIONES DEL PANEL VUELVEN AL PANEL
 *
 * `wp_safe_redirect()` solo admite por defecto el host de `home`. Con el panel
 * en otro host, toda acción que vuelve a `admin.php?page=…` (probar SMTP,
 * backups, importar config, HWE Logs) caía a su fallback, `admin_url()`: el
 * escritorio, sin el aviso del resultado. Se añade solo el host de `siteurl`
 * (el del propio panel), nada externo.
 * ---------------------------------------------------------------------- */
function hwe_allow_admin_redirect_host( $hosts ) {
	$hosts      = is_array( $hosts ) ? $hosts : array();
	$admin_host = wp_parse_url( site_url(), PHP_URL_HOST );
	if ( is_string( $admin_host ) && '' !== $admin_host && ! in_array( $admin_host, $hosts, true ) ) {
		$hosts[] = $admin_host;
	}
	return $hosts;
}
add_filter( 'allowed_redirect_hosts', 'hwe_allow_admin_redirect_host' );
