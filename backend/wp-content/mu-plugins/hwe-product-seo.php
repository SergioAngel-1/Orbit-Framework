<?php
/**
 * Plugin Name: HWE Product SEO
 * Description: Título y descripción SEO por producto, override opcional del
 *              nombre/descripción corta nativos para <title> y meta
 *              description. Se edita en la ficha de CUALQUIER producto y se
 *              expone por WPGraphQL como `Product.seoTitle` /
 *              `Product.seoDescription`. Mismo patrón que hwe-post-seo.php.
 *              (Las traducciones las aporta hwe-content-i18n.)
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_PRODUCT_SEO_TITLE_MAXLENGTH       = 60;
const HWE_PRODUCT_SEO_DESCRIPTION_MAXLENGTH = 160;

add_action( 'add_meta_boxes_product', function ( WP_Post $post ): void {
	add_meta_box( 'hwe_product_seo', __( 'SEO del producto', 'hwe' ), 'hwe_product_seo_render_box', 'product', 'side', 'default' );
} );

function hwe_product_seo_render_box( WP_Post $post ): void {
	wp_nonce_field( 'hwe_product_seo_save', 'hwe_product_seo_nonce' );
	$title = (string) get_post_meta( $post->ID, '_hwe_seo_title', true );
	$desc  = (string) get_post_meta( $post->ID, '_hwe_seo_description', true );
	echo '<p><label for="hwe_product_seo_title"><strong>' . esc_html__( 'Título SEO', 'hwe' ) . '</strong></label><br>';
	echo '<input type="text" id="hwe_product_seo_title" name="hwe_product_seo_title" maxlength="' . esc_attr( (string) HWE_PRODUCT_SEO_TITLE_MAXLENGTH ) . '" style="width:100%" value="' . esc_attr( $title ) . '"></p>';
	echo '<p><label for="hwe_product_seo_description"><strong>' . esc_html__( 'Descripción SEO', 'hwe' ) . '</strong></label><br>';
	echo '<textarea id="hwe_product_seo_description" name="hwe_product_seo_description" maxlength="' . esc_attr( (string) HWE_PRODUCT_SEO_DESCRIPTION_MAXLENGTH ) . '" rows="3" style="width:100%">' . esc_textarea( $desc ) . '</textarea></p>';
	echo '<p class="description">' . esc_html__( 'Opcional. Si se dejan vacíos, el sitio público usa el nombre y la descripción corta del producto. Máx. 60 / 160 caracteres (guía SERP).', 'hwe' ) . '</p>';
}

/** Guarda el SEO del producto (función nombrada para poder probarla). */
function hwe_product_seo_save( int $post_id ): void {
	if ( ! isset( $_POST['hwe_product_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hwe_product_seo_nonce'] ) ), 'hwe_product_seo_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$title = isset( $_POST['hwe_product_seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_product_seo_title'] ) ) : '';
	$desc  = isset( $_POST['hwe_product_seo_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hwe_product_seo_description'] ) ) : '';
	$title = mb_substr( $title, 0, HWE_PRODUCT_SEO_TITLE_MAXLENGTH );
	$desc  = mb_substr( $desc, 0, HWE_PRODUCT_SEO_DESCRIPTION_MAXLENGTH );

	if ( $title === '' ) {
		delete_post_meta( $post_id, '_hwe_seo_title' );
	} else {
		update_post_meta( $post_id, '_hwe_seo_title', $title );
	}
	if ( $desc === '' ) {
		delete_post_meta( $post_id, '_hwe_seo_description' );
	} else {
		update_post_meta( $post_id, '_hwe_seo_description', $desc );
	}
}
add_action( 'save_post_product', 'hwe_product_seo_save' );

/** Expone el SEO en WPGraphQL como `Product.seoTitle` / `Product.seoDescription`. */
function hwe_product_seo_register_graphql(): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}

	$resolve_meta = static function ( string $meta_key ): callable {
		return function ( $product ) use ( $meta_key ): ?string {
			$id = 0;
			if ( is_object( $product ) ) {
				if ( isset( $product->databaseId ) ) {
					$id = (int) $product->databaseId;
				} elseif ( isset( $product->ID ) ) {
					$id = (int) $product->ID;
				}
			}
			$value = $id ? (string) get_post_meta( $id, $meta_key, true ) : '';
			return $value !== '' ? $value : null;
		};
	};

	register_graphql_field( 'Product', 'seoTitle', [
		'type'        => 'String',
		'description' => __( 'Título SEO override (<title> / og:title). Null = el frontend usa el nombre del producto.', 'hwe' ),
		'resolve'     => $resolve_meta( '_hwe_seo_title' ),
	] );
	register_graphql_field( 'Product', 'seoDescription', [
		'type'        => 'String',
		'description' => __( 'Descripción SEO override (meta description / og:description). Null = el frontend usa la descripción corta del producto.', 'hwe' ),
		'resolve'     => $resolve_meta( '_hwe_seo_description' ),
	] );
}
add_action( 'graphql_register_types', 'hwe_product_seo_register_graphql' );
