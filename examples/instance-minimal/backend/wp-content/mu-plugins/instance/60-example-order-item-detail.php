<?php
/**
 * Ejemplo: detalle de línea de pedido (`hwe-order-item-details`).
 *
 * La instancia declara la clave de extensión, la etiqueta que verán los correos y wp-admin, a qué
 * productos aplica y cómo se valida lo que manda el navegador. El núcleo lo toma de
 * `extensions` en `cart/add-item`, lo valida en el servidor (si no vale, el ítem NO entra al
 * carrito), impide pagar una línea que no lo lleva y lo guarda como meta VISIBLE de la línea del
 * pedido. El frontend lo envía con `useCart().addItem(id, qty, { example_note: "…" })`.
 */

add_filter( 'hwe_order_item_details', static function ( $details ) {
	if ( ! is_array( $details ) ) {
		return $details;
	}
	$details['example_note'] = array(
		'label'      => 'Nota de la línea (ejemplo)',
		// Slugs de producto a los que aplica; sin esta lista el detalle se descarta.
		'applies_to' => array( 'example-product' ),
		'required'   => true,
		'validate'   => static function ( $raw, int $product_id ) {
			$note = is_string( $raw ) ? trim( $raw ) : '';
			if ( '' === $note || mb_strlen( $note ) > 120 ) {
				return new WP_Error( 'example_note_invalid', 'La nota debe tener entre 1 y 120 caracteres.' );
			}
			return $note;
		},
	);
	return $details;
} );
