<?php
/**
 * Plugin Name: GraphQL Protection & Cache
 * Description: Protección del endpoint /graphql contra abusos: limita profundidad
 *              y complejidad de consultas, desactiva la introspección en producción,
 *              y habilita la caché de respuestas WPGraphQL (aprovecha Redis).
 * Author:      Headless Web Ecosystem
 * Version:     2.0.0
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
 *    Se desactiva SALVO que el entorno sea explícitamente no productivo. Usa
 *    wp_get_environment_type() y no la constante cruda: WordPress ya considera
 *    «production» por defecto cuando WP_ENVIRONMENT_TYPE no está definida, así que
 *    comprobar solo `defined('WP_ENVIRONMENT_TYPE')` fallaba ABIERTO (introspección
 *    activa) precisamente en ese caso —el despliegue más común si se olvida fijar la
 *    constante—. Falla cerrado por defecto.
 *    Compatible con WPGraphQL 1.14+ (filter) y versiones anteriores (constante).
 * ------------------------------------------------------------------------ */
add_filter(
	'graphql_introspection_enabled',
	static function ( bool $enabled ): bool {
		if ( 'production' === wp_get_environment_type() ) {
			return false;
		}
		return $enabled;
	}
);

// Fallback para WPGraphQL < 1.14: constante global.
if ( 'production' === wp_get_environment_type() && ! defined( 'GRAPHQL_INTROSPECTION_ENABLED' ) ) {
	define( 'GRAPHQL_INTROSPECTION_ENABLED', false );
}

/* ---------------------------------------------------------------------------
 * 4) NO HAY CACHÉ DE RESPUESTAS WPGraphQL, y es deliberado
 *
 *    Aquí vivía un `add_filter( 'graphql_cache_active', … )` que leía la opción
 *    `graphql_cache_section`. Era código muerto por dos motivos:
 *
 *      1. Ese filtro y esa opción los publica wp-graphql-smart-cache, un plugin
 *         que `backend/scripts/setup.sh` NO instala. Sin él, nadie lee el filtro.
 *      2. Aun instalándolo no serviría: Smart Cache cachea consultas GET y el BFF
 *         consulta siempre por POST (`lib/graphql-client.ts`). Haría falta pasar
 *         a queries persistidas (GET + `queryId`), un cambio de contrato.
 *
 *    Lo que SÍ cachea: Redis Object Cache dentro de WordPress, y en el frontend
 *    la Data Cache de Next (con `tags` e invalidación por `/api/revalidate`) y la
 *    Full Route Cache (ISR).
 * ------------------------------------------------------------------------ */

/* ---------------------------------------------------------------------------
 * 5) REGISTRO DE CONSULTAS RECHAZADAS (log)
 *    Cada consulta que supere profundidad o complejidad, o que pida
 *    introspección en producción, queda registrada en el log de WordPress.
 *
 *    Se registra UNA sola vez a nivel de plugin. Antes se añadía dentro de
 *    `graphql_execute_request`, que apilaba un callback nuevo por ejecución:
 *    una petición con varias operaciones en lote duplicaba las líneas de log.
 * ------------------------------------------------------------------------ */
function hwe_graphql_log_rejected( $result ) {
	if ( is_wp_error( $result ) || ( is_array( $result ) && isset( $result['errors'] ) ) ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions
		error_log( sprintf(
			'[hwe] graphql: consulta rechazada desde %s: %s',
			$ip,
			is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $result['errors'] )
		) );
	}
	return $result;
}
add_filter( 'graphql_request_results', 'hwe_graphql_log_rejected' );
