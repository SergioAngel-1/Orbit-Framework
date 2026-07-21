<?php

namespace HWE\Banners;

/**
 * Registro del Custom Post Type "Banner".
 *
 * Headless: public=false y show_in_rest=false a propósito — no queremos rutas
 * públicas nativas ni la REST por defecto de WP para banners. La lectura pública
 * se sirve por el endpoint propio del plugin (RestApi), y la gestión por wp-admin.
 */
class PostType {

	public const SLUG = 'hwe_banner';

	public static function register(): void {
		$labels = [
			'name'               => __( 'Banners', 'hwe-banners' ),
			'singular_name'      => __( 'Banner', 'hwe-banners' ),
			'menu_name'          => __( 'Banners', 'hwe-banners' ),
			'add_new'            => __( 'Añadir nuevo', 'hwe-banners' ),
			'add_new_item'       => __( 'Añadir nuevo banner', 'hwe-banners' ),
			'new_item'           => __( 'Nuevo banner', 'hwe-banners' ),
			'edit_item'          => __( 'Editar banner', 'hwe-banners' ),
			'view_item'          => __( 'Ver banner', 'hwe-banners' ),
			'all_items'          => __( 'Todos los banners', 'hwe-banners' ),
			'search_items'       => __( 'Buscar banners', 'hwe-banners' ),
			'not_found'          => __( 'No se encontraron banners', 'hwe-banners' ),
			'not_found_in_trash' => __( 'No hay banners en la papelera', 'hwe-banners' ),
		];

		register_post_type( self::SLUG, [
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'hierarchical'        => false,
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-images-alt2',
			'supports'            => [ 'title' ],
		] );
	}
}
