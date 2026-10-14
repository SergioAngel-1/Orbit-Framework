<?php
/**
 * Plugin Name: HWE Imagen de referencia
 * Description: Casilla «Imagen de referencia» en cada imagen de la medioteca.
 *              Marca una foto GENÉRICA que varios productos usan mientras no
 *              tengan la suya (p. ej. el envase común de una gama), y se expone
 *              por WPGraphQL como `MediaItem.isReferenceImage`.
 *              La ficha de producto pinta la etiqueta «Imagen de referencia»
 *              sobre esa foto.
 *
 *              El dato va en la IMAGEN, no en el producto ni en la categoría:
 *              se marca una vez para todos los productos que la usan, y el
 *              producto que reciba su foto propia pierde la etiqueta solo, sin
 *              que nadie tenga que acordarse de desmarcar nada.
 *
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** '1' = imagen de referencia; '0' = desmarcada a mano (un seed que solo rellene huecos no la pisa). */
const HWE_MEDIA_REFERENCE_META = '_hwe_reference_image';

function hwe_media_reference_is_marked( int $attachment_id ): bool {
	return get_post_meta( $attachment_id, HWE_MEDIA_REFERENCE_META, true ) === '1';
}

// ── Casilla en la ficha del adjunto (modal de la medioteca y «Editar medio») ─
add_filter( 'attachment_fields_to_edit', function ( array $form_fields, WP_Post $post ): array {
	if ( ! wp_attachment_is_image( $post ) ) {
		return $form_fields;
	}
	$name = 'attachments[' . $post->ID . ']';
	// El marcador oculto no es decorativo: el modal guarda estos campos por
	// AJAX (`save-attachment-compat`) y responde error si
	// `attachments[<id>]` llega vacío — que es justo lo que manda una casilla
	// DESMARCADA sin nada más. Sin él, desmarcar no se guardaría nunca.
	$html  = '<input type="hidden" name="' . esc_attr( $name . '[hwe_reference_image_present]' ) . '" value="1">';
	$html .= '<label><input type="checkbox" name="' . esc_attr( $name . '[hwe_reference_image]' ) . '" value="1"'
		. checked( hwe_media_reference_is_marked( $post->ID ), true, false ) . '> '
		. esc_html__( 'Es una foto genérica, no la del producto', 'hwe' ) . '</label>';

	$form_fields['hwe_reference_image'] = [
		'label' => __( 'Imagen de referencia', 'hwe' ),
		'input' => 'html',
		'html'  => $html,
		'helps' => __( 'La ficha de cada producto que use esta imagen muestra la etiqueta «Imagen de referencia».', 'hwe' ),
	];
	return $form_fields;
}, 10, 2 );

function hwe_media_reference_save( array $post, array $attachment ): array {
	// Sin el marcador, la petición no venía del formulario de esta casilla
	// (otro guardado del adjunto): no se toca el valor.
	if ( empty( $attachment['hwe_reference_image_present'] ) ) {
		return $post;
	}
	$id = (int) ( $post['ID'] ?? 0 );
	if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
		return $post;
	}
	$marked = ! empty( $attachment['hwe_reference_image'] );
	if ( $marked === hwe_media_reference_is_marked( $id ) ) {
		return $post;
	}
	// Se guarda '0' al desmarcar (y no se borra el meta) para que un seed que
	// solo rellene huecos no vuelva a marcar lo que alguien desmarcó.
	update_post_meta( $id, HWE_MEDIA_REFERENCE_META, $marked ? '1' : '0' );
	// Guardar un adjunto no dispara el webhook de WooCommerce: sin esto, la
	// etiqueta tardaría la ventana ISR de la ficha (5 min) en aparecer.
	if ( class_exists( 'HWE\ControlCenter\Revalidation' ) ) {
		\HWE\ControlCenter\Revalidation::trigger( 'products' );
	}
	return $post;
}
add_filter( 'attachment_fields_to_save', 'hwe_media_reference_save', 10, 2 );

/** Expone la marca en WPGraphQL como `MediaItem.isReferenceImage`. */
add_action( 'graphql_register_types', function (): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}
	register_graphql_field( 'MediaItem', 'isReferenceImage', [
		'type'        => [ 'non_null' => 'Boolean' ],
		'description' => __( 'La imagen es una foto genérica compartida, no la del producto: la ficha la etiqueta como «Imagen de referencia».', 'hwe' ),
		'resolve'     => static function ( $media ): bool {
			$id = 0;
			if ( is_object( $media ) ) {
				if ( isset( $media->databaseId ) ) {
					$id = (int) $media->databaseId;
				} elseif ( isset( $media->ID ) ) {
					$id = (int) $media->ID;
				}
			}
			return $id > 0 && hwe_media_reference_is_marked( $id );
		},
	] );
} );
