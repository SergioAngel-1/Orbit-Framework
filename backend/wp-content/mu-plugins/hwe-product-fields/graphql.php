<?php
/**
 * WPGraphQL de los campos de producto declarativos.
 *
 * `Product.hweFields(locale: String)`: lista de pares `{ key, value }` con los campos que tienen
 * valor, ya resueltos al idioma. Un idioma que no es uno de los secundarios del sitio, o un campo
 * sin traducción, devuelve el valor del idioma por defecto. Sin valor, el campo no sale: la
 * ausencia es «no hay dato».
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Valores de los campos de un producto en un idioma.
 *
 * @return list<array{key:string,value:string}>
 */
function hwe_product_fields_values( int $productId, string $locale = '' ): array {
	$suffix = '';
	if ( '' !== $locale && function_exists( 'hwe_i18n_locale_suffix' ) && function_exists( 'hwe_i18n_locales' ) ) {
		$candidate = hwe_i18n_locale_suffix( $locale );
		$suffix    = in_array( $candidate, hwe_i18n_locales(), true ) ? $candidate : '';
	}

	$out = array();
	foreach ( hwe_product_fields() as $key => $field ) {
		$value = '';
		if ( '' !== $suffix && $field['translatable'] && function_exists( 'hwe_i18n_meta_key' ) ) {
			$value = (string) get_post_meta( $productId, hwe_i18n_meta_key( $key, $suffix ), true );
		}
		if ( '' === $value ) {
			$value = (string) get_post_meta( $productId, hwe_product_field_meta_key( $key ), true );
		}
		if ( '' !== $value ) {
			$out[] = array( 'key' => $key, 'value' => $value );
		}
	}
	return $out;
}

add_action(
	'graphql_register_types',
	static function (): void {
		if ( ! function_exists( 'register_graphql_field' ) || ! function_exists( 'register_graphql_object_type' ) ) {
			return;
		}
		register_graphql_object_type(
			'HweProductField',
			array(
				'description' => __( 'Un campo de producto declarado por la instancia, con su valor.', 'hwe' ),
				'fields'      => array(
					'key'   => array( 'type' => array( 'non_null' => 'String' ) ),
					'value' => array( 'type' => array( 'non_null' => 'String' ) ),
				),
			)
		);
		register_graphql_field(
			'Product',
			'hweFields',
			array(
				'type'        => array( 'list_of' => array( 'non_null' => 'HweProductField' ) ),
				'description' => __( 'Campos de producto declarados (`hwe_product_fields`) que tienen valor, resueltos al idioma pedido.', 'hwe' ),
				'args'        => array(
					'locale' => array(
						'type'        => 'String',
						'description' => __( 'Idioma secundario del sitio (p. ej. «en»); sin él o sin traducción, el idioma por defecto.', 'hwe' ),
					),
				),
				'resolve'     => static function ( $product, array $args ): array {
					$id = is_object( $product ) ? (int) ( $product->databaseId ?? $product->ID ?? 0 ) : 0;
					return $id > 0 ? hwe_product_fields_values( $id, (string) ( $args['locale'] ?? '' ) ) : array();
				},
			)
		);
	}
);
