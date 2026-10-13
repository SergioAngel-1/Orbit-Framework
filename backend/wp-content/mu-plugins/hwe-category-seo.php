<?php
/**
 * Plugin Name: HWE Category SEO
 * Description: Título y descripción SEO por categoría de producto. Se editan
 *              en el editor de la categoría y se exponen por WPGraphQL como
 *              `ProductCategory.seoTitle` / `seoDescription`.
 *              Equivalente de hwe-product-seo.php para taxonomías.
 *              (Las traducciones las aporta hwe-content-i18n.)
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_CATEGORY_SEO_TITLE_MAXLENGTH       = 60;
const HWE_CATEGORY_SEO_DESCRIPTION_MAXLENGTH = 160;

/** campo del formulario => [meta, máximo, etiqueta, ¿textarea?] */
function hwe_category_seo_fields(): array {
	return [
		'hwe_category_seo_title'          => [ '_hwe_seo_title', HWE_CATEGORY_SEO_TITLE_MAXLENGTH, __( 'Título SEO', 'hwe' ), false ],
		'hwe_category_seo_description'    => [ '_hwe_seo_description', HWE_CATEGORY_SEO_DESCRIPTION_MAXLENGTH, __( 'Descripción SEO', 'hwe' ), true ],
	];
}

function hwe_category_seo_input( string $field, int $max, bool $textarea, string $value, string $style ): string {
	if ( $textarea ) {
		return '<textarea id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" maxlength="' . esc_attr( (string) $max ) . '" rows="3"' . $style . '>' . esc_textarea( $value ) . '</textarea>';
	}
	return '<input type="text" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" maxlength="' . esc_attr( (string) $max ) . '"' . $style . ' value="' . esc_attr( $value ) . '">';
}

/** Campos en el formulario de "añadir categoría". */
add_action( 'product_cat_add_form_fields', function (): void {
	wp_nonce_field( 'hwe_category_seo_save', 'hwe_category_seo_nonce' );
	foreach ( hwe_category_seo_fields() as $field => [ $meta, $max, $label, $textarea ] ) {
		echo '<div class="form-field"><label for="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label>';
		echo hwe_category_seo_input( $field, $max, $textarea, '', '' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función
		/* translators: %d: máximo de caracteres */
		echo '<p>' . esc_html( sprintf( __( 'Opcional. Máx. %d caracteres.', 'hwe' ), $max ) ) . '</p></div>';
	}
} );

/** Campos en el formulario de "editar categoría". */
add_action( 'product_cat_edit_form_fields', function ( WP_Term $term ): void {
	wp_nonce_field( 'hwe_category_seo_save', 'hwe_category_seo_nonce' );
	foreach ( hwe_category_seo_fields() as $field => [ $meta, $max, $label, $textarea ] ) {
		$value = (string) get_term_meta( $term->term_id, $meta, true );
		echo '<tr class="form-field"><th><label for="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo hwe_category_seo_input( $field, $max, $textarea, $value, ' style="width:95%"' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función
		/* translators: %d: máximo de caracteres */
		echo '<p class="description">' . esc_html( sprintf( __( 'Opcional. Máx. %d caracteres.', 'hwe' ), $max ) ) . '</p></td></tr>';
	}
} );

function hwe_category_seo_save( int $term_id ): void {
	if ( ! isset( $_POST['hwe_category_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hwe_category_seo_nonce'] ) ), 'hwe_category_seo_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_product_terms' ) ) {
		return;
	}
	foreach ( hwe_category_seo_fields() as $field => [ $meta, $max, $label, $textarea ] ) {
		$raw   = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
		$value = $textarea ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		$value = mb_substr( $value, 0, $max );
		if ( $value === '' ) {
			delete_term_meta( $term_id, $meta );
		} else {
			update_term_meta( $term_id, $meta, $value );
		}
	}
}
add_action( 'created_product_cat', 'hwe_category_seo_save' );
add_action( 'edited_product_cat', 'hwe_category_seo_save' );

/** Expone el SEO en WPGraphQL. */
function hwe_category_seo_register_graphql(): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}

	$resolver = static function ( string $meta_key ): callable {
		return static function ( $term ) use ( $meta_key ): ?string {
			$id = 0;
			if ( is_object( $term ) ) {
				if ( isset( $term->term_id ) ) {
					$id = (int) $term->term_id;
				} elseif ( isset( $term->databaseId ) ) {
					$id = (int) $term->databaseId;
				}
			}
			$value = $id ? (string) get_term_meta( $id, $meta_key, true ) : '';
			return $value !== '' ? $value : null;
		};
	};

	$fields = [
		'seoTitle'         => [ '_hwe_seo_title', __( 'Título SEO override de la categoría. Null = el frontend usa el nombre.', 'hwe' ) ],
		'seoDescription'   => [ '_hwe_seo_description', __( 'Meta descripción override de la categoría. Null = el frontend usa la descripción.', 'hwe' ) ],
	];
	foreach ( $fields as $name => [ $meta, $description ] ) {
		register_graphql_field( 'ProductCategory', $name, [
			'type'        => 'String',
			'description' => $description,
			'resolve'     => $resolver( $meta ),
		] );
	}
}
add_action( 'graphql_register_types', 'hwe_category_seo_register_graphql' );
