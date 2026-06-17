<?php
/**
 * Plugin Name: Headless Configuration
 * Description: Convierte esta instalación de WordPress en un CMS exclusivamente Headless:
 *              bloquea el frontend nativo, redirige el tráfico humano a /wp-admin,
 *              mantiene viva la API de WPGraphQL y aplica cabeceras CORS al endpoint.
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 *
 * Al ser un "must-use plugin" (mu-plugin) se carga automáticamente y no puede
 * desactivarse desde el panel, lo que garantiza el comportamiento headless.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Acceso directo no permitido.
}

/**
 * Devuelve el origen permitido para CORS (la URL del frontend).
 */
function hwe_frontend_origin(): string {
	if ( defined( 'HEADLESS_FRONTEND_URL' ) && HEADLESS_FRONTEND_URL ) {
		return rtrim( HEADLESS_FRONTEND_URL, '/' );
	}
	return 'http://localhost:3000';
}

/**
 * Determina si la petición actual va dirigida al endpoint de GraphQL.
 */
function hwe_is_graphql_request(): bool {
	if ( function_exists( 'is_graphql_http_request' ) && is_graphql_http_request() ) {
		return true;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	return ( false !== strpos( (string) $uri, '/graphql' ) );
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
		$origin  = hwe_frontend_origin();
		$request = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		// Refleja el origen solo si coincide con el frontend autorizado.
		$headers['Access-Control-Allow-Origin']      = ( $request === $origin ) ? $request : $origin;
		$headers['Access-Control-Allow-Credentials'] = 'true';
		$headers['Access-Control-Allow-Headers']     = 'Authorization, Content-Type, X-JWT-Auth, X-JWT-Refresh';
		$headers['Access-Control-Allow-Methods']     = 'GET, POST, OPTIONS';
		$headers['Vary']                             = 'Origin';

		return $headers;
	}
);

/**
 * Responde de inmediato a las peticiones preflight (OPTIONS) del endpoint GraphQL.
 */
add_action(
	'init',
	function () {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'OPTIONS' === $method && hwe_is_graphql_request() ) {
			$origin  = hwe_frontend_origin();
			$request = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

			header( 'Access-Control-Allow-Origin: ' . ( $request === $origin ? $request : $origin ) );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-JWT-Auth, X-JWT-Refresh' );
			header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
			header( 'Access-Control-Max-Age: 600' );
			status_header( 204 );
			exit;
		}
	},
	1
);

/* -------------------------------------------------------------------------
 * 4) DESACTIVAR EL EDITOR DE ARCHIVOS DEL PANEL (buena práctica de seguridad)
 * ---------------------------------------------------------------------- */
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}
