<?php
/**
 * Plugin Name: Rate-Limit para WordPress
 * Description: Defensa en profundidad contra abusos en endpoints de API y en el
 *              login nativo. Limita peticiones a /graphql, /wp-json y los intentos
 *              de POST a wp-login.php por IP usando transients de WordPress
 *              (respaldados por Redis cuando está disponible).
 *
 *              Es una capa complementaria al rate-limit del BFF (Next.js):
 *              mientras el BFF protege las llamadas desde el frontend, este
 *              plugin protege WordPress de ataques directos o bypass del proxy.
 *
 *              Las llamadas del BFF (secreto interno válido, el mismo que exige
 *              hwe-bff-only) NO consumen el presupuesto por IP de /graphql ni de
 *              /wp-json: en producción llegan por la red interna con la IP del
 *              contenedor del frontend, compartida por todos los compradores.
 *              Sin cabecera, con un secreto incorrecto o sin la constante
 *              definida se cuentan como cualquier otra petición. El login de
 *              wp-login.php se cuenta siempre.
 *
 * Author:      Sergio Jauregui
 * Version:     2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// La comprobación del secreto interno del BFF es la MISMA que aplica
// `hwe-bff-only.php` (una sola copia: ver `hwe-bff-secret.php`).
require_once __DIR__ . '/hwe-bff-secret.php';

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

// wp-login.php es el único login humano permitido (headless-config.php) y no tenía
// ninguna defensa contra fuerza bruta ni credential-stuffing: este archivo solo cubría
// /graphql y /wp-json/. Con WP_ADMIN_USER=admin por defecto el usuario ya se conoce.
// Limita INTENTOS (POST con credenciales, incluida la acción lostpassword), no vistas
// de la página (GET). Presupuesto propio: no compite con la API.
const HWE_RL_LOGIN = [
	'limit'     => 5,
	'window'    => 300, // 5 min
	'prefix'    => 'hwe_rl_login_',
];

/* ---------------------------------------------------------------------------
 * Arranque: hookeamos init con prioridad alta para interceptar antes de que
 * WPGraphQL o la REST API procesen la petición.
 * ------------------------------------------------------------------------ */
add_action( 'init', 'hwe_rate_limit_init', 1 );

function hwe_rate_limit_init(): void {
	// Se clasifica por la RUTA parseada, nunca por el URI completo: la query la
	// controla el cliente y una subcadena allí (`?x=/wp-json/`, `?x=/graphql`…)
	// movía un intento de login a otro presupuesto y dejaba el de login intacto.
	// Mismo patrón que `hwe_is_graphql_request()` en headless-config.php.
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path   = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';

	// 1) El login se decide PRIMERO y solo gasta el cubo de login. Cualquier query
	//    (`?action=lostpassword`, `?x=/wp-json/`…) se ignora para clasificar, igual
	//    que hace WordPress al elegir el manejador por la ruta física.
	//    `HWE_Login_Slug`: el login movido a su propia ruta (hwe-login-slug.php)
	//    tiene que seguir contando intentos, o moverlo lo dejaría sin defensa.
	//    Solo el envío de credenciales (POST): cargar la página (GET) no cuenta, para
	//    no bloquear a quien solo la visita.
	if ( 'POST' === $method
		&& ( (bool) preg_match( '#(?:^|/)wp-login\.php(?:/|$)#', $path )
			|| ( class_exists( 'HWE_Login_Slug' ) && HWE_Login_Slug::isLoginRequest() ) ) ) {
		hwe_check_rate_limit( HWE_RL_LOGIN );
		return;
	}

	// 2) GraphQL y REST por SEGMENTO de la ruta parseada, más las formas con las
	//    que WordPress despacha cada API cuando no usa enlaces permanentes
	//    (`?graphql`, `?rest_route=`). Las llamadas del BFF no gastan presupuesto:
	//    ver `hwe_rate_limit_is_bff_request()`; el login nunca se exime (es tráfico
	//    humano directo; el BFF no inicia sesión en wp-login.php).
	$is_graphql = (bool) preg_match( '#(?:^|/)graphql(?:/|$)#', $path )
		|| isset( $_GET['graphql'] ) || isset( $_POST['graphql'] );

	if ( $is_graphql ) {
		if ( ! hwe_rate_limit_is_bff_request() ) {
			hwe_check_rate_limit( HWE_RL_GRAPHQL );
		}
		return;
	}

	$is_rest = (bool) preg_match( '#(?:^|/)wp-json(?:/|$)#', $path )
		|| isset( $_GET['rest_route'] ) || isset( $_POST['rest_route'] );

	if ( $is_rest && ! hwe_rate_limit_is_bff_request() ) {
		hwe_check_rate_limit( HWE_RL_REST );
	}
	// El resto de rutas (admin, etc.) no tienen rate-limit aquí.
}

/* ---------------------------------------------------------------------------
 * ¿La petición es del BFF (secreto interno válido)?
 *
 * En producción el BFF habla con WordPress por la red interna sin
 * `X-Forwarded-For`: `REMOTE_ADDR` es la IP del contenedor del frontend, la misma
 * para TODOS los compradores. Contar esas llamadas contra el presupuesto por IP
 * hacía que unos pocos usuarios a la vez agotaran el límite y WordPress devolviera
 * 429 a toda la tienda (Store API, pedidos, fidelización).
 *
 * La exención es una CREDENCIAL (`X-HWE-Internal-Secret` con
 * `HWE_REVALIDATION_SECRET`), no una suposición sobre la red: la comprueba
 * `hwe_bff_only_has_valid_secret()` con comparación en tiempo constante, y es la
 * misma regla que la puerta de `hwe-bff-only.php` (no una copia).
 *
 * Falla cerrado: sin la constante definida, sin cabecera, con un valor incorrecto
 * o si la comprobación revienta, la petición se cuenta como cualquier otra.
 * ------------------------------------------------------------------------ */
function hwe_rate_limit_is_bff_request(): bool {
	if ( ! function_exists( 'hwe_bff_only_has_valid_secret' ) ) {
		return false;
	}

	try {
		return (bool) hwe_bff_only_has_valid_secret();
	} catch ( \Throwable $e ) {
		return false;
	}
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
 *
 * SEGURIDAD: el PRIMER valor de X-Forwarded-For lo controla el cliente (es
 * falsificable). Cada proxy de confianza añade a la DERECHA la IP de su par.
 * Con N proxies de confianza (HWE_TRUSTED_PROXY_COUNT, por defecto 1), la IP
 * real del cliente es el valor a N posiciones del final. Tomar el primero a
 * ciegas permitiría falsear la IP y evadir el rate-limit.
 * ------------------------------------------------------------------------ */
function hwe_get_client_ip(): string {
	$count = defined( 'HWE_TRUSTED_PROXY_COUNT' ) ? (int) HWE_TRUSTED_PROXY_COUNT : 1;

	if ( $count > 0 && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$ips = array_values( array_filter( array_map(
			'trim',
			explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )
		) ) );
		if ( ! empty( $ips ) ) {
			$idx = max( 0, count( $ips ) - $count );
			$ip  = $ips[ $idx ];
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
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
