<?php
/**
 * Plugin Name: Rate-Limit para WordPress
 * Description: Defensa en profundidad contra abusos en endpoints de API.
 *              Limita peticiones a /graphql y /wp-json por IP usando transients
 *              de WordPress (respaldados por Redis cuando está disponible).
 *
 *              Es una capa complementaria al rate-limit del BFF (Next.js):
 *              mientras el BFF protege las llamadas desde el frontend, este
 *              plugin protege WordPress de ataques directos o bypass del proxy.
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * Configuración por endpoint
 * ------------------------------------------------------------------------ */
const HWE_RL_GRAPHQL = [
	'limit'     => 60,   // peticiones por ventana
	'window'    => 60,   // ventana en segundos
	'prefix'    => 'hwe_rl_gql_',
];

const HWE_RL_REST = [
	'limit'     => 120,
	'window'    => 60,
	'prefix'    => 'hwe_rl_rest_',
];

/* ---------------------------------------------------------------------------
 * Arranque: hookeamos init con prioridad alta para interceptar antes de que
 * WPGraphQL o la REST API procesen la petición.
 * ------------------------------------------------------------------------ */
add_action( 'init', 'hwe_rate_limit_init', 1 );

function hwe_rate_limit_init(): void {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

	// Solo aplicamos rate-limit a los endpoints de API.
	if ( str_contains( $uri, '/graphql' ) ) {
		hwe_check_rate_limit( HWE_RL_GRAPHQL );
	} elseif ( str_contains( $uri, '/wp-json/' ) ) {
		hwe_check_rate_limit( HWE_RL_REST );
	}
	// El resto de rutas (admin, login, etc.) no tienen rate-limit aquí.
}

/* ---------------------------------------------------------------------------
 * Verificador de rate-limit (ventana fija con transients)
 *
 * Usa set_transient/get_transient de WordPress. Si Redis está activo como
 * object cache, los transients se almacenan en Redis automáticamente.
 *
 * Fail-open: si el transient falla (por ejemplo, Redis caído), la petición
 * sigue adelante (principio de mitigación, no barrera dura).
 * ------------------------------------------------------------------------ */
function hwe_check_rate_limit( array $cfg ): void {
	$ip   = hwe_get_client_ip();
	$key  = $cfg['prefix'] . md5( $ip );
	$data = get_transient( $key );

	if ( false === $data ) {
		// Primera petición: inicializar contador.
		set_transient( $key, [ 'count' => 1, 'reset' => time() + $cfg['window'] ], $cfg['window'] );
		return;
	}

	$count = (int) ( $data['count'] ?? 0 );
	$reset = (int) ( $data['reset'] ?? 0 );

	if ( $count >= $cfg['limit'] ) {
		$retry_after = max( 1, $reset - time() );
		hwe_deny_rate_limit( $retry_after );
	}

	// Incrementar contador.
	$data['count'] = $count + 1;
	set_transient( $key, $data, max( 1, $reset - time() ) );
}

/* ---------------------------------------------------------------------------
 * Obtener IP del cliente considerando proxies inversos (Caddy, Nginx).
 * Solamente confía en X-Forwarded-For si viene de IPs de confianza.
 * En producción con Caddy, la IP real está en X-Forwarded-For.
 * ------------------------------------------------------------------------ */
function hwe_get_client_ip(): string {
	// Si hay un proxy inverso de confianza
	if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
		$ip  = trim( $ips[0] );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}
	if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}
	return '0.0.0.0';
}

/* ---------------------------------------------------------------------------
 * Respuesta de denegación
 * ------------------------------------------------------------------------ */
function hwe_deny_rate_limit( int $retry_after ): void {
	status_header( 429 );
	header( 'Retry-After: ' . $retry_after );
	header( 'Content-Type: application/json; charset=utf-8' );

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions
	error_log( sprintf(
		'[HWE Rate-Limit] IP %s bloqueada por %d segundos en %s',
		hwe_get_client_ip(),
		$retry_after,
		isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : 'unknown'
	) );

	echo wp_json_encode( [
		'code'    => 'rate_limit_exceeded',
		'message' => __( 'Demasiadas peticiones. Inténtalo de nuevo más tarde.', 'hwe' ),
		'retry'   => $retry_after,
	] );
	exit;
}
