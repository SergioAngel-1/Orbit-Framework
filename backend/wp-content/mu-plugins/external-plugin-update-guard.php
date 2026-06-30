<?php
/**
 * Plugin Name: HWE — Guard de actualizaciones de plugins externos
 * Description: Evita FALSAS actualizaciones desde WordPress.org para plugins que distribuimos
 *  fuera del repositorio oficial (GitHub/Composer). Caso típico: la carpeta "jwt-auth" de
 *  WPGraphQL JWT Authentication colisiona en slug con el plugin homónimo "JWT Auth" (Useful Team)
 *  de wordpress.org, y WordPress ofrece su versión 3.x como "actualización" — que en realidad es
 *  un plugin DISTINTO e instalarlo rompería la autenticación headless.
 *
 * Por qué pasa: estos plugins no traen el header `Update URI` (WP 5.8+), así que el core consulta
 *  wordpress.org por su slug de carpeta. Si el slug coincide con un plugin real de wordpress.org,
 *  el core muestra esa versión ajena como update. Este mu-plugin los excluye del check.
 *
 * @package HWE\ControlCenter
 */

namespace HWE\UpdateGuard;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Carpetas (slug) de plugins gestionados fuera de wordpress.org.
 * Se comparan contra el primer segmento del "plugin file" (carpeta/archivo.php).
 */
const EXTERNAL_PLUGIN_SLUGS = array(
	'jwt-auth',                      // WPGraphQL JWT Auth instalado con carpeta legacy "jwt-auth"
	'wp-graphql-jwt-authentication', // mismo plugin con la carpeta correcta
	'wp-graphql-cors',               // funkhaus/wp-graphql-cors (GitHub)
);

/**
 * Elimina de la respuesta de actualizaciones cualquier entrada cuyo slug de carpeta
 * pertenezca a un plugin externo. Cubre tanto la sección `response` (genera el aviso
 * y el botón "update now") como `no_update`.
 *
 * @param mixed $transient El transient site_transient_update_plugins.
 * @return mixed
 */
function strip_external_updates( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}

	foreach ( array( 'response', 'no_update' ) as $bucket ) {
		if ( empty( $transient->$bucket ) || ! is_array( $transient->$bucket ) ) {
			continue;
		}
		foreach ( array_keys( $transient->$bucket ) as $plugin_file ) {
			$slug = strtok( (string) $plugin_file, '/' ); // carpeta del plugin
			if ( in_array( $slug, EXTERNAL_PLUGIN_SLUGS, true ) ) {
				unset( $transient->$bucket[ $plugin_file ] );
			}
		}
	}

	return $transient;
}
add_filter( 'site_transient_update_plugins', __NAMESPACE__ . '\\strip_external_updates', 100 );

/**
 * Defensa extra: evita que el panel de "información del plugin" (thickbox) muestre los
 * datos del plugin ajeno de wordpress.org para estos slugs.
 *
 * @param mixed  $result Resultado de la API de plugins.
 * @param string $action Acción solicitada.
 * @param object $args   Argumentos (incluye ->slug).
 * @return mixed
 */
function block_external_plugin_information( $result, $action, $args ) {
	if ( 'plugin_information' === $action && isset( $args->slug )
		&& in_array( $args->slug, EXTERNAL_PLUGIN_SLUGS, true ) ) {
		return new \WP_Error(
			'hwe_external_plugin',
			__( 'Plugin gestionado fuera de WordPress.org (GitHub). Actualízalo vía el script de despliegue.', 'hwe' )
		);
	}
	return $result;
}
add_filter( 'plugins_api', __NAMESPACE__ . '\\block_external_plugin_information', 100, 3 );
