<?php
/**
 * Plugin Name: HWE Product SEO
 * Description: Título y descripción SEO por producto, override opcional del
 *              nombre/descripción corta nativos para <title> y meta description.
 *              La caja, el guardado y el registro en GraphQL viven en
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

/*
 * Caja en la barra lateral (contexto `side`), no en `normal`: en el editor de bloques
 * `normal` queda bajo el panel «Meta Boxes» colapsado y nadie la encuentra.
 */
add_action( 'add_meta_boxes_product', function (): void {
	add_meta_box( 'hwe_product_seo', __( 'SEO del producto', 'hwe' ), 'hwe_product_seo_render_box', 'product', 'side', 'default' );
} );

function hwe_product_seo_render_box( WP_Post $post ): void {
	echo hwe_seo_render_fields( // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función
		'product',
		hwe_seo_current_values( 'post', $post->ID ),
		'box',
		array(
			'title'       => __( 'Ideal: 50–60 caracteres. El sitio añade la marca al final solo si el total cabe en 60.', 'hwe' ),
			'description' => __( 'Ideal: 120–160 caracteres. Opcional: vacío = se usa la descripción corta del producto.', 'hwe' ),
		)
	);
}

/** Guarda el SEO (función nombrada para poder probarla). */
function hwe_product_seo_save( int $post_id ): void {
	hwe_seo_save_post_fields( 'product', $post_id );
}
add_action( 'save_post_product', 'hwe_product_seo_save' );

hwe_seo_register_list_column( 'product' );

/** Expone el SEO en WPGraphQL como `Product.seoTitle` / `Product.seoDescription`. */
function hwe_product_seo_register_graphql(): void {
	hwe_seo_register_graphql_fields( 'Product', 'post', array(
		'seoTitle'       => __( 'Título SEO override (<title> / og:title). Null = el frontend usa el nombre del producto.', 'hwe' ),
		'seoDescription' => __( 'Descripción SEO override (meta description / og:description). Null = el frontend usa la descripción corta del producto.', 'hwe' ),
	) );
	if ( function_exists( 'register_graphql_field' ) ) {
		register_graphql_field( 'Product', 'hweGtin', array(
			'type'        => 'String',
			'description' => __( 'GTIN del producto (EAN, UPC, ISBN) para el JSON-LD. Null = sin código válido.', 'hwe' ),
			'resolve'     => static function ( $node ): ?string {
				$id = is_object( $node ) && isset( $node->databaseId ) ? (int) $node->databaseId : 0;
				return $id > 0 ? hwe_product_seo_gtin( $id ) : null;
			},
		) );
	}
}
add_action( 'graphql_register_types', 'hwe_product_seo_register_graphql' );

/**
 * GTIN del producto: el campo nativo «GTIN, UPC, EAN o ISBN» de WooCommerce (9.2+).
 * Solo se devuelve con un largo que el estándar admite (8, 12, 13 o 14 dígitos): un
 * código mal escrito invalida el nodo `Product` entero, y es mejor no declararlo.
 */
function hwe_product_seo_gtin( int $product_id ): ?string {
	$value = '';
	if ( function_exists( 'wc_get_product' ) ) {
		$product = wc_get_product( $product_id );
		if ( $product && method_exists( $product, 'get_global_unique_id' ) ) {
			$value = (string) $product->get_global_unique_id();
		}
	}
	if ( '' === $value ) {
		$value = (string) get_post_meta( $product_id, '_global_unique_id', true );
	}
	$digits = (string) preg_replace( '/\D/', '', $value );
	return in_array( strlen( $digits ), array( 8, 12, 13, 14 ), true ) ? $digits : null;
}
