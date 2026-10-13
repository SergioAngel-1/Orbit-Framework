<?php
/**
 * HWE Geo: `GET /wp-json/hwe/v1/geo` y el tag ISR `geo`.
 *
 * Público y cacheable, como `/config`: son datos geográficos sin nada privado. Sirve SIEMPRE el
 * paquete del país de la tienda (`ecommerce.country`); no acepta un país por parámetro, así que
 * nadie puede usarlo para sondear paquetes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'hwe_geo_register_route' );
add_filter( 'hwe_revalidation_tags', 'hwe_geo_revalidation_tags' );
add_action( 'hwe_control_center_saved', 'hwe_geo_on_config_saved' );

function hwe_geo_register_route(): void {
	register_rest_route(
		'hwe/v1',
		'/geo',
		array(
			'methods'             => 'GET',
			'callback'            => 'hwe_geo_handle',
			'permission_callback' => '__return_true',
		)
	);
}

/** El paquete tal como lo recibe el frontend (camelCase). */
function hwe_geo_public_pack( array $pack, bool $fallback, string $requested ): array {
	return array(
		'country'       => $pack['country'],
		'name'          => $pack['name'],
		'fallback'      => $fallback,
		'requested'     => $requested,
		'dialCode'      => $pack['dial_code'],
		'divisionLabel' => $pack['division_label'],
		'address'       => array(
			'division'     => $pack['address']['division'],
			'postcode'     => $pack['address']['postcode'],
			'cityDivision' => $pack['address']['city_division'],
		),
		'divisions'     => $pack['divisions'],
		'cities'        => $pack['cities'],
		'documentTypes' => $pack['document_types'],
	);
}

function hwe_geo_handle( WP_REST_Request $request ): WP_REST_Response {
	$resolved = hwe_geo_resolve( hwe_geo_store_country() );
	$response = new WP_REST_Response(
		hwe_geo_public_pack( $resolved['pack'], $resolved['fallback'], $resolved['requested'] ),
		200
	);
	$response->header( 'Cache-Control', 'public, max-age=300, s-maxage=300, stale-while-revalidate=600' );
	return $response;
}

/**
 * Declara el tag `geo`: sin esto el núcleo no envía su revalidación.
 *
 * @param mixed $tags
 * @return list<string>
 */
function hwe_geo_revalidation_tags( $tags ): array {
	$tags = is_array( $tags ) ? array_values( $tags ) : array();
	if ( ! in_array( 'geo', $tags, true ) ) {
		$tags[] = 'geo';
	}
	return $tags;
}

/** Cambiar el país de la tienda cambia el paquete: invalida la lectura del frontend. */
function hwe_geo_on_config_saved(): void {
	if ( class_exists( 'HWE\\ControlCenter\\Revalidation' ) ) {
		HWE\ControlCenter\Revalidation::trigger( 'geo' );
	}
}
