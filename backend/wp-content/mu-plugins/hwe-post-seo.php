<?php
/**
 * Plugin Name: HWE Post SEO
 * Description: Título y descripción SEO por entrada de blog, override opcional
 *              del título/resumen nativos para <title> y meta description.
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
add_action( 'add_meta_boxes_post', function (): void {
	add_meta_box( 'hwe_post_seo', __( 'SEO de la entrada', 'hwe' ), 'hwe_post_seo_render_box', 'post', 'side', 'default' );
} );

function hwe_post_seo_render_box( WP_Post $post ): void {
	echo hwe_seo_render_fields( // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en la función
		'post',
		hwe_seo_current_values( 'post', $post->ID ),
		'box',
		array(
			'title'       => __( 'Ideal: 50–60 caracteres. El sitio añade la marca al final solo si el total cabe en 60.', 'hwe' ),
			'description' => __( 'Ideal: 120–160 caracteres. Opcional: vacío = se usa el comienzo del texto de la entrada.', 'hwe' ),
		)
	);
}

/** Guarda el SEO (función nombrada para poder probarla). */
function hwe_post_seo_save( int $post_id ): void {
	hwe_seo_save_post_fields( 'post', $post_id );
}
add_action( 'save_post_post', 'hwe_post_seo_save' );

hwe_seo_register_list_column( 'post' );

/** Expone el SEO en WPGraphQL como `Post.seoTitle` / `Post.seoDescription`. */
function hwe_post_seo_register_graphql(): void {
	hwe_seo_register_graphql_fields( 'Post', 'post', array(
		'seoTitle'       => __( 'Título SEO override (<title> / og:title). Null = el frontend usa el título de la entrada.', 'hwe' ),
		'seoDescription' => __( 'Descripción SEO override (meta description / og:description). Null = el frontend usa el resumen de la entrada.', 'hwe' ),
	) );
}
add_action( 'graphql_register_types', 'hwe_post_seo_register_graphql' );
