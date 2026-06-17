<?php
/**
 * Plugin Name: Headless Security Hardening
 * Description: Endurecimiento de la superficie de ataque de WordPress en modo headless:
 *              bloquea la enumeración de usuarios (REST API y ?author=N), desactiva
 *              pingbacks/trackbacks, generaliza los errores de login y reduce metadatos.
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 *
 * Forma parte de la Fase 1 (endurecimiento de seguridad base) del plan de producción.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1) BLOQUEO DE ENUMERACIÓN DE USUARIOS VÍA REST API
 *    /wp-json/wp/v2/users solo debe responder a usuarios autenticados con
 *    capacidad de listar usuarios. Para peticiones públicas -> 401.
 * ---------------------------------------------------------------------- */
add_filter(
	'rest_endpoints',
	function ( $endpoints ) {
		if ( is_user_logged_in() && current_user_can( 'list_users' ) ) {
			return $endpoints; // El panel y los usuarios autorizados siguen funcionando.
		}

		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}
);

/* -------------------------------------------------------------------------
 * 2) BLOQUEO DE ENUMERACIÓN VÍA ?author=N
 *    Una petición pública con ?author=N revela el slug del usuario. La cortamos.
 * ---------------------------------------------------------------------- */
add_action(
	'init',
	function () {
		if ( is_admin() ) {
			return;
		}
		if ( isset( $_GET['author'] ) && ! is_user_logged_in() ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}
);

// Elimina el enlace de autor del oEmbed (otra vía de fuga del slug).
add_filter(
	'oembed_response_data',
	function ( $data ) {
		unset( $data['author_url'], $data['author_name'] );
		return $data;
	}
);

/* -------------------------------------------------------------------------
 * 3) PINGBACKS / TRACKBACKS / XML-RPC
 * ---------------------------------------------------------------------- */
// Quita la cabecera X-Pingback.
add_filter(
	'wp_headers',
	function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

// Desactiva los métodos de pingback de XML-RPC (XML-RPC ya está deshabilitado
// globalmente en headless-config.php, esto es defensa en profundidad).
add_filter(
	'xmlrpc_methods',
	function ( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}
);

/* -------------------------------------------------------------------------
 * 4) ERRORES DE LOGIN GENÉRICOS
 *    Evita revelar si el fallo fue por usuario o por contraseña.
 * ---------------------------------------------------------------------- */
add_filter(
	'login_errors',
	function () {
		return __( 'Las credenciales no son válidas.', 'hwe' );
	}
);

/* -------------------------------------------------------------------------
 * 5) REDUCCIÓN DE METADATOS / SUPERFICIE
 * ---------------------------------------------------------------------- */
// No exponer la versión de WordPress en feeds ni en recursos encolados.
add_filter( 'the_generator', '__return_empty_string' );

// Desactiva las "Application Passwords" si no se usan para integraciones REST.
// (El flujo de autenticación de esta plantilla es JWT vía WPGraphQL.)
add_filter( 'wp_is_application_passwords_available', '__return_false' );
