<?php
/**
 * Ejemplo: campos de producto declarativos (`hwe-product-fields`).
 *
 * La instancia declara clave, etiqueta, tipo, si es traducible y en qué caja va; el núcleo pinta
 * la caja en la ficha del producto, sanea al guardar y expone el valor por WPGraphQL como
 * `Product.hweFields(locale)`. El frontend lo declara en `instanceRegistry.productFields`
 * (mismas claves y tipos) y lo tipa con `ProductFieldValues`.
 */

add_filter( 'hwe_product_fields', static function ( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}
	$fields['example_usage'] = array(
		'label'        => 'Modo de uso (ejemplo)',
		'type'         => 'textarea',
		'translatable' => true,
		'box'          => 'Ejemplo de la instancia',
		'max'          => 255,
	);
	$fields['example_weight'] = array(
		'label' => 'Peso en gramos (ejemplo)',
		'type'  => 'number',
		'box'   => 'Ejemplo de la instancia',
	);
	return $fields;
} );
