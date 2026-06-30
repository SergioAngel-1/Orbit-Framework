<?php
/**
 * Plugin Name: GraphQL Protection & Cache
 * Description: Protección del endpoint /graphql contra abusos: limita profundidad
 *              y complejidad de consultas, desactiva la introspección en producción,
 *              y habilita la caché de respuestas WPGraphQL (aprovecha Redis).
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 *
 * Forma parte del plan de hardening (Fase C.2) de la plantilla Headless Web Ecosystem.
 * Sin estos límites, /graphql es un vector de DoS por consultas deeply anidadas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * 1) LÍMITE DE PROFUNDIDAD DE CONSULTA
 *    WPGraphQL rechaza consultas que superen el anidamiento máximo.
 *    Por defecto: 15 niveles. Puede ajustarse definiendo GRAPHQL_MAX_DEPTH
 *    en wp-config.php.
 * ------------------------------------------------------------------------ */
add_filter(
	'graphql_query_max_depth',
	static function ( int $depth ): int {
		return defined( 'GRAPHQL_MAX_DEPTH' ) ? (int) GRAPHQL_MAX_DEPTH : 15;
	}
);

/* ---------------------------------------------------------------------------
 * 2) LÍMITE DE COMPLEJIDAD DE CONSULTA
 *    Cada campo en una consulta suma un punto de complejidad. Si la suma
 *    supera el límite, la consulta se rechaza antes de ejecutarse.
 *    Por defecto: 1000. Puede ajustarse definiendo GRAPHQL_QUERY_COMPLEXITY_LIMIT.
 * ------------------------------------------------------------------------ */
add_filter(
	'graphql_query_complexity',
	static function ( int $complexity ): int {
		return defined( 'GRAPHQL_QUERY_COMPLEXITY_LIMIT' )
			? (int) GRAPHQL_QUERY_COMPLEXITY_LIMIT
			: 1000;
	}
);

/* ---------------------------------------------------------------------------
 * 3) DESACTIVAR INTROSPECCIÓN EN PRODUCCIÓN
 *    La introspección revela el esquema completo (tipos, campos, argumentos).
 *    Es útil en desarrollo pero en producción expone toda la superficie de la API.
 *
 *    Se desactiva automáticamente cuando WP_ENVIRONMENT_TYPE === 'production'.
 *    Compatible con WPGraphQL 1.14+ (filter) y versiones anteriores (constante).
 * ------------------------------------------------------------------------ */
add_filter(
	'graphql_introspection_enabled',
	static function ( bool $enabled ): bool {
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'production' ) {
			return false;
		}
		return $enabled;
	}
);

// Fallback para WPGraphQL < 1.14: constante global.
if ( defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'production' ) {
	if ( ! defined( 'GRAPHQL_INTROSPECTION_ENABLED' ) ) {
		define( 'GRAPHQL_INTROSPECTION_ENABLED', false );
	}
}

/* ---------------------------------------------------------------------------
 * 4) ACTIVAR CACHÉ DE RESPUESTAS WPGraphQL
 *    Cuando Redis está disponible (WP_REDIS_HOST definido), WPGraphQL puede
 *    cachear respuestas de consultas GET (consultas de lectura: catálogo, etc.)
 *    para evitar round-trips a la base de datos.
 *
 *    La opción 'graphql_cache_section' se activa en setup.sh.
 *    Este filtro garantiza que la constante interna se defina correctamente.
 * ------------------------------------------------------------------------ */
add_filter(
	'graphql_cache_active',
	static function ( bool $active ): bool {
		if ( defined( 'WP_CACHE' ) && WP_CACHE && defined( 'WP_REDIS_HOST' ) ) {
			$stored = get_option( 'graphql_cache_section', 'off' );
			return $stored === 'on';
		}
		return false;
	}
);

/* ---------------------------------------------------------------------------
 * 5) REGISTRO DE CONSULTAS RECHAZADAS (log)
 *    Cada consulta que supere profundidad, complejidad o que introspección
 *    en producción queda registrada en el log de depuración de WordPress.
 * ------------------------------------------------------------------------ */
add_action(
	'graphql_execute_request',
	static function ( $response = null ) {
		add_filter(
			'graphql_request_results',
			static function ( $result ) {
				if ( is_wp_error( $result ) || ( is_array( $result ) && isset( $result['errors'] ) ) ) {
					$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions
					error_log( sprintf(
						'[HWE GraphQL] Consulta rechazada desde %s: %s',
						$ip,
						is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result['errors'] )
					) );
				}
				return $result;
			}
		);
	}
);
