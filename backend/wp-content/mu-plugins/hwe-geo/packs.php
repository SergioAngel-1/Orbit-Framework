<?php
/**
 * HWE Geo: registro de paquetes por país.
 *
 * Un paquete describe lo que un formulario de dirección necesita saber de un país: divisiones
 * administrativas, ciudades con su división, tipos de documento, prefijo telefónico y la política
 * de la dirección. El núcleo trae `CO` y `generic`; una instancia añade o sustituye paquetes con el
 * filtro `hwe_geo_packs` (ver `instance/README.md`).
 *
 * Forma de un paquete (claves en snake_case; el REST las entrega en camelCase):
 *   country (ISO 3166-1 alfa-2 en mayúsculas, o `generic`), name, dial_code (`+57` o vacío),
 *   division_label, address { division: required|optional|free|none, postcode: required|optional|none,
 *   city_division: bool }, divisions [{code, name}], cities [{name, division}],
 *   document_types [{code, label, pattern}], geocoding { abbreviations {abreviatura: forma completa},
 *   drop_tokens [string] } (opcional: reglas del geocodificador de `shipping`; por defecto vacías).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_GEO_GENERIC = 'generic';

/** Una línea por problema y proceso: los paquetes se evalúan varias veces por petición. */
function hwe_geo_log_once( string $key, string $message ): void {
	static $seen = array();
	if ( isset( $seen[ $key ] ) ) {
		return;
	}
	$seen[ $key ] = true;
	error_log( '[hwe] geo: ' . $message );
}

/** Paquetes que trae el núcleo, ya normalizados. @return array<string,array> */
function hwe_geo_core_packs(): array {
	$packs = array();
	foreach ( array( 'generic', 'co' ) as $file ) {
		$raw  = require __DIR__ . '/packs/' . $file . '.php';
		$pack = hwe_geo_normalize_pack( $raw );
		if ( null !== $pack ) {
			$packs[ $pack['country'] ] = $pack;
		}
	}
	return $packs;
}

/**
 * Todos los paquetes disponibles: los del núcleo más los del filtro `hwe_geo_packs`.
 * Un paquete mal formado se descarta (y se avisa) sin tirar los demás.
 *
 * @return array<string,array> Por código de país.
 */
function hwe_geo_packs(): array {
	$packs    = hwe_geo_core_packs();
	$filtered = apply_filters( 'hwe_geo_packs', $packs );

	if ( ! is_array( $filtered ) ) {
		hwe_geo_log_once(
			'filter:not-array',
			sprintf( 'el filtro hwe_geo_packs debe devolver un array de paquetes (devolvió %s); solo se usan los del núcleo.', get_debug_type( $filtered ) )
		);
		return $packs;
	}

	$result = $packs;
	foreach ( $filtered as $raw ) {
		$pack = hwe_geo_normalize_pack( $raw );
		if ( null !== $pack ) {
			$result[ $pack['country'] ] = $pack;
		}
	}
	// El paquete de reserva siempre existe, aunque un filtro lo quite o lo rompa.
	if ( ! isset( $result[ HWE_GEO_GENERIC ] ) ) {
		$result[ HWE_GEO_GENERIC ] = $packs[ HWE_GEO_GENERIC ];
	}
	return $result;
}

/** Texto no vacío recortado, o null. */
function hwe_geo_text( $value, int $max = 120 ): ?string {
	if ( ! is_string( $value ) ) {
		return null;
	}
	$value = trim( $value );
	return '' !== $value && mb_strlen( $value ) <= $max ? $value : null;
}

/**
 * Valida y normaliza un paquete. Devuelve null si no se puede usar.
 *
 * Es estricta porque los paquetes de instancia llegan por un filtro: la integridad referencial
 * (cada ciudad apunta a una división que existe), los códigos únicos y los patrones compilables se
 * comprueban aquí y no en cada formulario.
 *
 * @param mixed $raw
 */
function hwe_geo_normalize_pack( $raw ): ?array {
	if ( ! is_array( $raw ) ) {
		hwe_geo_log_once( 'pack:not-array:' . get_debug_type( $raw ), 'un paquete de hwe_geo_packs no es un array: se descarta.' );
		return null;
	}

	$country = is_string( $raw['country'] ?? null ) ? $raw['country'] : '';
	if ( HWE_GEO_GENERIC !== $country && 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
		hwe_geo_log_once( 'pack:country:' . $country, sprintf( 'paquete descartado: «country» debe ser ISO 3166-1 alfa-2 en mayúsculas o «generic» (llegó «%s»).', $country ) );
		return null;
	}
	$name = hwe_geo_text( $raw['name'] ?? null );
	if ( null === $name ) {
		hwe_geo_log_once( 'pack:name:' . $country, sprintf( 'paquete «%s» descartado: «name» es obligatorio.', $country ) );
		return null;
	}

	$dial = $raw['dial_code'] ?? '';
	if ( ! is_string( $dial ) || ( '' !== $dial && 1 !== preg_match( '/^\+[1-9]\d{0,3}$/', $dial ) ) ) {
		hwe_geo_log_once( 'pack:dial:' . $country, sprintf( 'paquete «%s»: «dial_code» no es un prefijo válido (+57): se deja vacío.', $country ) );
		$dial = '';
	}

	$address_in = is_array( $raw['address'] ?? null ) ? $raw['address'] : array();
	$division_mode = in_array( $address_in['division'] ?? null, array( 'required', 'optional', 'free', 'none' ), true ) ? $address_in['division'] : 'free';
	$postcode_mode = in_array( $address_in['postcode'] ?? null, array( 'required', 'optional', 'none' ), true ) ? $address_in['postcode'] : 'optional';

	$geocoding = hwe_geo_normalize_geocoding( $raw['geocoding'] ?? null, $country );

	// Divisiones: código único; el nombre es lo que ve el cliente.
	$divisions = array();
	foreach ( is_array( $raw['divisions'] ?? null ) ? $raw['divisions'] : array() as $division ) {
		$code  = is_array( $division ) ? hwe_geo_text( $division['code'] ?? null, 20 ) : null;
		$dname = is_array( $division ) ? hwe_geo_text( $division['name'] ?? null ) : null;
		if ( null === $code || null === $dname || isset( $divisions[ $code ] ) ) {
			hwe_geo_log_once( 'pack:division:' . $country . ':' . wp_json_encode( $division ), sprintf( 'paquete «%s»: división inválida o repetida, se omite.', $country ) );
			continue;
		}
		$divisions[ $code ] = array( 'code' => $code, 'name' => $dname );
	}

	// Ciudades: deben apuntar a una división del mismo paquete (o a ninguna si no hay divisiones).
	$cities = array();
	foreach ( is_array( $raw['cities'] ?? null ) ? $raw['cities'] : array() as $city ) {
		$cname = is_array( $city ) ? hwe_geo_text( $city['name'] ?? null ) : null;
		$cdiv  = is_array( $city ) && is_string( $city['division'] ?? null ) ? $city['division'] : '';
		if ( null === $cname || isset( $cities[ $cname ] ) || ( '' !== $cdiv && ! isset( $divisions[ $cdiv ] ) ) ) {
			hwe_geo_log_once( 'pack:city:' . $country . ':' . wp_json_encode( $city ), sprintf( 'paquete «%s»: ciudad inválida, repetida o con una división que no existe, se omite.', $country ) );
			continue;
		}
		$cities[ $cname ] = array( 'name' => $cname, 'division' => $cdiv );
	}

	// Tipos de documento: el patrón tiene que compilar o el documento nunca validaría.
	$documents = array();
	foreach ( is_array( $raw['document_types'] ?? null ) ? $raw['document_types'] : array() as $doc ) {
		$code    = is_array( $doc ) ? hwe_geo_text( $doc['code'] ?? null, 20 ) : null;
		$label   = is_array( $doc ) ? hwe_geo_text( $doc['label'] ?? null ) : null;
		$pattern = is_array( $doc ) && is_string( $doc['pattern'] ?? null ) ? $doc['pattern'] : '';
		$valid   = '' !== $pattern && false !== @preg_match( '~' . str_replace( '~', '\~', $pattern ) . '~', '' );
		if ( null === $code || null === $label || ! $valid || isset( $documents[ $code ] ) ) {
			hwe_geo_log_once( 'pack:doc:' . $country . ':' . wp_json_encode( $doc ), sprintf( 'paquete «%s»: tipo de documento inválido (código, etiqueta o patrón), se omite.', $country ) );
			continue;
		}
		$documents[ $code ] = array( 'code' => $code, 'label' => $label, 'pattern' => $pattern );
	}

	return array(
		'country'        => $country,
		'name'           => $name,
		'dial_code'      => $dial,
		'division_label' => hwe_geo_text( $raw['division_label'] ?? null ) ?? 'Provincia o estado',
		'address'        => array(
			'division'      => $division_mode,
			'postcode'      => $postcode_mode,
			'city_division' => (bool) ( $address_in['city_division'] ?? false ) && array() !== $cities,
		),
		'divisions'      => array_values( $divisions ),
		'cities'         => array_values( $cities ),
		'document_types' => array_values( $documents ),
		'geocoding'      => $geocoding,
	);
}

/**
 * Reglas opcionales para el geocodificador: abreviaturas de vía (abreviatura → forma completa) y
 * tokens que se descartan. Un valor mal formado se omite y se registra; sin reglas, ambas listas
 * van vacías (el paquete sigue siendo válido).
 *
 * @param mixed $raw
 * @return array{abbreviations: array<string,string>, drop_tokens: string[]}
 */
function hwe_geo_normalize_geocoding( $raw, string $country ): array {
	$input         = is_array( $raw ) ? $raw : array();
	$abbreviations = array();
	foreach ( is_array( $input['abbreviations'] ?? null ) ? $input['abbreviations'] : array() as $abbr => $full ) {
		$text = is_string( $abbr ) ? trim( $abbr ) : '';
		$form = hwe_geo_text( $full, 60 );
		if ( '' === $text || null === $form || mb_strlen( $text ) > 10 ) {
			hwe_geo_log_once( 'pack:abbr:' . $country . ':' . $text, sprintf( 'paquete «%s»: abreviatura de vía inválida, se omite.', $country ) );
			continue;
		}
		$abbreviations[ $text ] = $form;
	}

	$tokens = array();
	foreach ( is_array( $input['drop_tokens'] ?? null ) ? $input['drop_tokens'] : array() as $token ) {
		$text = hwe_geo_text( $token, 40 );
		if ( null === $text ) {
			hwe_geo_log_once( 'pack:token:' . $country . ':' . wp_json_encode( $token ), sprintf( 'paquete «%s»: token a descartar inválido, se omite.', $country ) );
			continue;
		}
		$tokens[] = $text;
	}

	return array(
		'abbreviations' => $abbreviations,
		'drop_tokens'   => array_values( array_unique( $tokens ) ),
	);
}

/**
 * País de la tienda (`ecommerce.country`): el guardado o, si no hay, el del esquema.
 * En mayúsculas y recortado.
 */
function hwe_geo_store_country(): string {
	$value = class_exists( 'HWE\\ControlCenter\\Storage' ) ? HWE\ControlCenter\Storage::get( array( 'ecommerce', 'country' ) ) : null;
	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		$defaults = class_exists( 'HWE\\ControlCenter\\Walkers\\DefaultsWalker' ) && class_exists( 'HWE\\ControlCenter\\Schema' )
			? ( new HWE\ControlCenter\Walkers\DefaultsWalker() )->walk( HWE\ControlCenter\Schema::core() )
			: array();
		$value = $defaults['ecommerce']['country'] ?? '';
	}
	return is_string( $value ) ? strtoupper( trim( $value ) ) : '';
}

/**
 * Paquete del país pedido. Un país sin paquete cae al genérico y se registra (una vez).
 *
 * @return array{pack: array, fallback: bool, requested: string}
 */
function hwe_geo_resolve( string $country ): array {
	$packs     = hwe_geo_packs();
	$requested = strtoupper( trim( $country ) );
	if ( '' !== $requested && isset( $packs[ $requested ] ) && HWE_GEO_GENERIC !== $requested ) {
		return array( 'pack' => $packs[ $requested ], 'fallback' => false, 'requested' => $requested );
	}
	hwe_geo_log_once(
		'fallback:' . $requested,
		sprintf( 'no hay paquete para el país «%s»: se usa el genérico (texto libre). Añade uno con el filtro hwe_geo_packs.', $requested )
	);
	return array( 'pack' => $packs[ HWE_GEO_GENERIC ], 'fallback' => true, 'requested' => $requested );
}
