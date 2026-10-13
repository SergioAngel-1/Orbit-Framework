<?php
/**
 * Cajas de la ficha del producto y guardado de los campos declarados.
 *
 * Una caja por título (`box`) declarado. Cada caja lleva su propio nonce; el guardado solo toca
 * los campos de las cajas que llegaron en el POST, así que una petición parcial (edición rápida,
 * importación) no borra nada.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Los campos agrupados por caja: id de caja → [ 'title', 'fields' => clave → datos ].
 *
 * @return array<string,array{title:string,fields:array<string,array<string,mixed>>}>
 */
function hwe_product_fields_boxes(): array {
	$boxes = array();
	foreach ( hwe_product_fields() as $key => $field ) {
		$id = 'hwe_pf_' . sanitize_title( $field['box'] );
		if ( 'hwe_pf_' === $id ) {
			$id = 'hwe_pf_datos';
		}
		$boxes[ $id ]['title']          = $boxes[ $id ]['title'] ?? $field['box'];
		$boxes[ $id ]['fields'][ $key ] = $field;
	}
	return $boxes;
}

add_action(
	'add_meta_boxes_product',
	static function ( $post ): void {
		foreach ( hwe_product_fields_boxes() as $id => $box ) {
			add_meta_box(
				$id,
				$box['title'],
				static function ( $post ) use ( $id, $box ): void {
					hwe_product_fields_render_box( $post, $id, $box['fields'] );
				},
				'product',
				'normal',
				'default'
			);
		}
	}
);

/**
 * @param array<string,array<string,mixed>> $fields
 */
function hwe_product_fields_render_box( $post, string $boxId, array $fields ): void {
	wp_nonce_field( 'hwe_product_fields_' . $boxId, $boxId . '_nonce' );
	foreach ( $fields as $key => $field ) {
		$name  = 'hwe_pf[' . $key . ']';
		$id    = 'hwe_pf_' . $key;
		$value = (string) get_post_meta( $post->ID, hwe_product_field_meta_key( $key ), true );

		echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $field['label'] ) . '</strong></label><br>';
		switch ( $field['type'] ) {
			case 'boolean':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( '1', $value, false ) . '> ' . esc_html__( 'Sí', 'hwe' ) . '</label>';
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				echo '<option value="">—</option>';
				foreach ( $field['options'] as $optionValue => $optionLabel ) {
					echo '<option value="' . esc_attr( (string) $optionValue ) . '"' . selected( $value, (string) $optionValue, false ) . '>' . esc_html( $optionLabel ) . '</option>';
				}
				echo '</select>';
				break;
			case 'textarea':
			case 'richtext':
				$max = $field['max'] > 0 ? ' maxlength="' . esc_attr( (string) $field['max'] ) . '"' : '';
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" style="width:100%"' . $max . '>' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'number':
				echo '<input type="number" step="any" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
				break;
			case 'color':
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="#rrggbb" maxlength="7" class="small-text">';
				break;
			case 'url':
			case 'image':
				echo '<input type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" style="width:100%">';
				break;
			default:
				$max = $field['max'] > 0 ? ' maxlength="' . esc_attr( (string) $field['max'] ) . '"' : '';
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" style="width:100%"' . $max . '>';
		}
		if ( '' !== $field['description'] ) {
			echo '<br><span class="description">' . esc_html( $field['description'] ) . '</span>';
		}
		echo '</p>';
	}
}

add_action(
	'save_post_product',
	static function ( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}
		$posted = isset( $_POST['hwe_pf'] ) && is_array( $_POST['hwe_pf'] ) ? wp_unslash( $_POST['hwe_pf'] ) : array();
		$changed = false;

		foreach ( hwe_product_fields_boxes() as $boxId => $box ) {
			$nonce = isset( $_POST[ $boxId . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $boxId . '_nonce' ] ) ) : '';
			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'hwe_product_fields_' . $boxId ) ) {
				continue;
			}
			foreach ( $box['fields'] as $key => $field ) {
				$value   = hwe_product_field_sanitize( $field, $posted[ $key ] ?? '' );
				$meta    = hwe_product_field_meta_key( $key );
				$current = (string) get_post_meta( $postId, $meta, true );
				if ( $value === $current ) {
					continue;
				}
				$changed = true;
				if ( '' === $value ) {
					delete_post_meta( $postId, $meta );
				} else {
					update_post_meta( $postId, $meta, $value );
				}
			}
		}

		// El webhook de WooCommerce solo se dispara con cambios del producto: un campo declarado
		// puede ser lo único que cambió.
		if ( $changed && class_exists( 'HWE\\ControlCenter\\Revalidation' ) ) {
			\HWE\ControlCenter\Revalidation::trigger( 'products' );
		}
	}
);
