<?php
/**
 * Plugin Name: HWE Page SEO
 * Description: Título y descripción SEO por página, override opcional del
 *              título/contenido nativos para <title> y meta description.
 *              La caja, el guardado y el registro en GraphQL viven en
 *              hwe-seo/fields.php (comunes a las cuatro entidades).
 *              (Las traducciones las aporta hwe-content-i18n.)
 *
 * Author:      Headless Web Ecosystem
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
add_action( 'add_meta_boxes_page', function (): void {
	add_meta_box( 'hwe_page_seo', __( 'SEO de la página', 'hwe' ), 'hwe_page_seo_render_box', 'page', 'side', 'default' );
} );

function hwe_page_seo_render_box( WP_Post $post ): void {
	echo hwe_seo_render_fields( // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función
		'page',
		hwe_seo_current_values( 'post', $post->ID ),
		'box',
		array(
			'title'       => __( 'Ideal: 50–60 caracteres. El sitio añade la marca al final solo si el total cabe en 60.', 'hwe' ),
			'description' => __( 'Ideal: 120–160 caracteres. Opcional: vacío = el frontend no emite descripción propia.', 'hwe' ),
		)
	);
}

/** Guarda el SEO (función nombrada para poder probarla). */
function hwe_page_seo_save( int $post_id ): void {
	hwe_seo_save_post_fields( 'page', $post_id );
}
add_action( 'save_post_page', 'hwe_page_seo_save' );

hwe_seo_register_list_column( 'page' );

/** Expone el SEO en WPGraphQL como `Page.seoTitle` / `Page.seoDescription`. */
function hwe_page_seo_register_graphql(): void {
	hwe_seo_register_graphql_fields( 'Page', 'post', array(
		'seoTitle'       => __( 'Título SEO override (<title> / og:title). Null = el frontend usa el título de la página.', 'hwe' ),
		'seoDescription' => __( 'Descripción SEO override (meta description / og:description). Null = el frontend usa su descripción por defecto.', 'hwe' ),
	) );
}
add_action( 'graphql_register_types', 'hwe_page_seo_register_graphql' );
