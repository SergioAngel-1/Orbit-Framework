<?php
/**
 * Plugin Name: GraphQL Protection & Cache
 * Description: Protección del endpoint /graphql contra abusos: limita profundidad
 *              y complejidad de consultas, desactiva la introspección en producción,
 *              y habilita la caché de respuestas WPGraphQL (aprovecha Redis).
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 *
 * Forma parte del plan de hardening (Fase C.2) de HWE Orbit.
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
 *
 *    El filtro es `graphql_query_depth_max` (WPGraphQL ≥ 2.24). Aquí hubo un
 *    `graphql_query_max_depth` que WPGraphQL nunca ha leído: el límite no se
 *    aplicaba. Le llega el valor del ajuste «Query Depth Limiting» (0 si está
 *    apagado, que es lo normal en una instalación previa a 2.24): devolver un
 *    número positivo aplica el límite igualmente. Un ajuste más estricto en el
 *    panel se respeta. La introspección nunca se limita (lo hace WPGraphQL).
 * ------------------------------------------------------------------------ */
function hwe_graphql_query_depth_max( $depth ): int {
	$limit = defined( 'GRAPHQL_MAX_DEPTH' ) ? (int) GRAPHQL_MAX_DEPTH : 15;
	$depth = is_numeric( $depth ) ? (int) $depth : 0;
	return $depth > 0 ? min( $depth, $limit ) : $limit;
}
add_filter( 'graphql_query_depth_max', 'hwe_graphql_query_depth_max' );

/* ---------------------------------------------------------------------------
 * 2) LÍMITE DE COMPLEJIDAD DE CONSULTA
 *    Cada campo en una consulta suma un punto de complejidad. Si la suma
 *    supera el límite, la consulta se rechaza antes de ejecutarse.
 *    Por defecto: 1000. Puede ajustarse definiendo GRAPHQL_QUERY_COMPLEXITY_LIMIT.
 *
 *    WPGraphQL no tiene filtro de complejidad (el `graphql_query_complexity` que
 *    hubo aquí no existe): entre las reglas estándar de webonyx/graphql-php va
 *    `QueryComplexity` DESACTIVADA, y se sustituye por una con límite. Cuenta los
 *    campos del documento, no los resultados: el tamaño de página lo acota
 *    WPGraphQL aparte (`graphql_connection_max_query_amount`, 100 por defecto).
 * ------------------------------------------------------------------------ */
function hwe_graphql_validation_rules( $rules ) {
	$rule = \GraphQL\Validator\Rules\QueryComplexity::class;
	if ( ! is_array( $rules ) || ! class_exists( $rule ) ) {
		return $rules;
	}
	$limit          = defined( 'GRAPHQL_QUERY_COMPLEXITY_LIMIT' ) ? (int) GRAPHQL_QUERY_COMPLEXITY_LIMIT : 1000;
	$rules[ $rule ] = new $rule( max( 1, $limit ) );
	return $rules;
}
add_filter( 'graphql_validation_rules', 'hwe_graphql_validation_rules' );

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
	$errors = null;
	if ( is_wp_error( $result ) ) {
		$errors = $result->get_error_message();
	} elseif ( is_array( $result ) && ! empty( $result['errors'] ) ) {
		$errors = wp_json_encode( $result['errors'] );
	} elseif ( $result instanceof \GraphQL\Executor\ExecutionResult && ! empty( $result->errors ) ) {
		// Por HTTP, WPGraphQL filtra el resultado de webonyx tal cual, sin pasarlo a array.
		$errors = wp_json_encode(
			array_map( static fn ( \Throwable $e ): array => [ 'message' => $e->getMessage() ], $result->errors )
		);
	}

	if ( null !== $errors ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions
		error_log( sprintf( '[hwe] graphql: consulta rechazada desde %s: %s', $ip, $errors ) );
	}
	return $result;
}
add_filter( 'graphql_request_results', 'hwe_graphql_log_rejected' );
