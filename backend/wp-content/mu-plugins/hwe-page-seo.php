<?php
/**
 * Plugin Name: HWE Page SEO
 * Description: Título y descripción SEO por página, override opcional
 *              del título/contenido nativos para <title> y meta
 *              description. Se expone por WPGraphQL como `Page.seoTitle` /
 *              `Page.seoDescription`. Mismo patrón que hwe-post-seo.php.
 *              (Las traducciones las aporta hwe-content-i18n.)
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_PAGE_SEO_TITLE_MAXLENGTH       = 60;
const HWE_PAGE_SEO_DESCRIPTION_MAXLENGTH = 160;

add_action( 'add_meta_boxes_page', function ( WP_Post $post ): void {
	add_meta_box( 'hwe_page_seo', __( 'SEO de la página', 'hwe' ), 'hwe_page_seo_render_box', 'page', 'side', 'default' );
} );

function hwe_page_seo_render_box( WP_Post $post ): void {
	wp_nonce_field( 'hwe_page_seo_save', 'hwe_page_seo_nonce' );
	$title = (string) get_post_meta( $post->ID, '_hwe_seo_title', true );
	$desc  = (string) get_post_meta( $post->ID, '_hwe_seo_description', true );
	echo '<p><label for="hwe_page_seo_title"><strong>' . esc_html__( 'Título SEO', 'hwe' ) . '</strong></label><br>';
	echo '<input type="text" id="hwe_page_seo_title" name="hwe_page_seo_title" maxlength="' . esc_attr( (string) HWE_PAGE_SEO_TITLE_MAXLENGTH ) . '" style="width:100%" value="' . esc_attr( $title ) . '"></p>';
	echo '<p><label for="hwe_page_seo_description"><strong>' . esc_html__( 'Descripción SEO', 'hwe' ) . '</strong></label><br>';
	echo '<textarea id="hwe_page_seo_description" name="hwe_page_seo_description" maxlength="' . esc_attr( (string) HWE_PAGE_SEO_DESCRIPTION_MAXLENGTH ) . '" rows="3" style="width:100%">' . esc_textarea( $desc ) . '</textarea></p>';
	echo '<p class="description">' . esc_html__( 'Opcional. Si se dejan vacíos, el frontend usa el título de la página y no emite meta description propia.', 'hwe' ) . '</p>';
}

/** Guarda el SEO de la página (función nombrada para poder probarla). */
function hwe_page_seo_save( int $post_id ): void {
	if ( ! isset( $_POST['hwe_page_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hwe_page_seo_nonce'] ) ), 'hwe_page_seo_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$title = isset( $_POST['hwe_page_seo_title'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['hwe_page_seo_title'] ) ), 0, HWE_PAGE_SEO_TITLE_MAXLENGTH ) : '';
	$desc  = isset( $_POST['hwe_page_seo_description'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['hwe_page_seo_description'] ) ), 0, HWE_PAGE_SEO_DESCRIPTION_MAXLENGTH ) : '';

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
add_action( 'save_post_page', 'hwe_page_seo_save' );

/** Expone el SEO en WPGraphQL como `Page.seoTitle` / `Page.seoDescription`. */
function hwe_page_seo_register_graphql(): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}

	$resolve_meta = static function ( string $meta_key ): callable {
		return function ( $page ) use ( $meta_key ): ?string {
			$id = 0;
			if ( is_object( $page ) ) {
				if ( isset( $page->databaseId ) ) {
					$id = (int) $page->databaseId;
				} elseif ( isset( $page->ID ) ) {
					$id = (int) $page->ID;
				}
			}
			$value = $id ? (string) get_post_meta( $id, $meta_key, true ) : '';
			return $value !== '' ? $value : null;
		};
	};

	register_graphql_field( 'Page', 'seoTitle', [
		'type'        => 'String',
		'description' => __( 'Título SEO override (<title>). Null = el frontend usa el título de la página.', 'hwe' ),
		'resolve'     => $resolve_meta( '_hwe_seo_title' ),
	] );
	register_graphql_field( 'Page', 'seoDescription', [
		'type'        => 'String',
		'description' => __( 'Descripción SEO override (meta description). Null = sin meta description propia.', 'hwe' ),
		'resolve'     => $resolve_meta( '_hwe_seo_description' ),
	] );
}
add_action( 'graphql_register_types', 'hwe_page_seo_register_graphql' );
