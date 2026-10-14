<?php
/**
 * Plugin Name: HWE Category SEO
 * Description: Título y descripción SEO por categoría de producto. Se editan
 *              en el editor de la categoría y se exponen por WPGraphQL como
 *              `ProductCategory.seoTitle` / `seoDescription`.
 *              Los campos, el guardado y el registro en GraphQL viven en
 *              hwe-seo/fields.php (comunes a las cuatro entidades).
 *              (Las traducciones las aporta hwe-content-i18n.)
 *
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/hwe-seo/fields.php';

/** Ayuda bajo cada campo. @return array{title:string,description:string} */
function hwe_category_seo_help(): array {
	return array(
		'title'       => __( 'Opcional. Ideal: 50–60 caracteres. Vacío = se usa el nombre.', 'hwe' ),
		'description' => __( 'Opcional. Ideal: 120–160 caracteres. Vacío = se usa la descripción.', 'hwe' ),
	);
}

/** Campos en el formulario de «añadir categoría». */
add_action( 'product_cat_add_form_fields', function (): void {
	echo hwe_seo_render_fields( 'category', array( 'title' => '', 'description' => '' ), 'term-add', hwe_category_seo_help() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función
} );

/** Campos en el formulario de «editar categoría». */
add_action( 'product_cat_edit_form_fields', function ( WP_Term $term ): void {
	echo hwe_seo_render_fields( 'category', hwe_seo_current_values( 'term', $term->term_id ), 'term-edit', hwe_category_seo_help() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función
} );

/** Guarda el SEO de la categoría (función nombrada para poder probarla). */
function hwe_category_seo_save( int $term_id ): void {
	hwe_seo_save_term_fields( 'category', $term_id, 'manage_product_terms' );
}
add_action( 'created_product_cat', 'hwe_category_seo_save' );
add_action( 'edited_product_cat', 'hwe_category_seo_save' );

/** Expone el SEO en WPGraphQL. */
function hwe_category_seo_register_graphql(): void {
	hwe_seo_register_graphql_fields( 'ProductCategory', 'term', array(
		'seoTitle'       => __( 'Título SEO override de la categoría. Null = el frontend usa el nombre.', 'hwe' ),
		'seoDescription' => __( 'Meta descripción override de la categoría. Null = el frontend usa la descripción.', 'hwe' ),
	) );
}
add_action( 'graphql_register_types', 'hwe_category_seo_register_graphql' );
