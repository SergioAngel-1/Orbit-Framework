<?php

namespace HWE\Shipping;

/**
 * Registro de los CPTs "Método de envío" y "Tarifa de envío".
 *
 * Headless: public=false y show_in_rest=false a propósito — no queremos rutas
 * públicas nativas ni la REST por defecto de WP. La lectura pública de métodos
 * se sirve por el endpoint propio del plugin (RestApi); la gestión, por wp-admin.
 * Las tarifas NO se exponen por REST: las consume directamente el filtro
 * `woocommerce_package_rates` (ShippingCost), server-side.
 */
class PostTypes {

	public const METHOD_SLUG = 'hwe_shipping_method';
	public const RATE_SLUG   = 'hwe_shipping_rate';

	public static function register(): void {
		register_post_type( self::METHOD_SLUG, [
			'labels'              => [
				'name'               => __( 'Métodos de envío', 'hwe-shipping' ),
				'singular_name'      => __( 'Método de envío', 'hwe-shipping' ),
				'menu_name'          => __( 'Métodos de envío', 'hwe-shipping' ),
				'add_new'            => __( 'Añadir nuevo', 'hwe-shipping' ),
				'add_new_item'       => __( 'Añadir nuevo método', 'hwe-shipping' ),
				'new_item'           => __( 'Nuevo método', 'hwe-shipping' ),
				'edit_item'          => __( 'Editar método', 'hwe-shipping' ),
				'view_item'          => __( 'Ver método', 'hwe-shipping' ),
				'all_items'          => __( 'Métodos de envío', 'hwe-shipping' ),
				'search_items'       => __( 'Buscar métodos', 'hwe-shipping' ),
				'not_found'          => __( 'No se encontraron métodos', 'hwe-shipping' ),
				'not_found_in_trash' => __( 'No hay métodos en la papelera', 'hwe-shipping' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			// Submenú bajo "Envío": la vista principal del menú son las tarifas.
			'show_in_menu'        => 'edit.php?post_type=' . self::RATE_SLUG,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'hierarchical'        => false,
			'supports'            => [ 'title' ],
		] );

		register_post_type( self::RATE_SLUG, [
			'labels'              => [
				'name'               => __( 'Tarifas de envío', 'hwe-shipping' ),
				'singular_name'      => __( 'Tarifa de envío', 'hwe-shipping' ),
				'menu_name'          => __( 'Envío', 'hwe-shipping' ),
				'add_new'            => __( 'Añadir nueva', 'hwe-shipping' ),
				'add_new_item'       => __( 'Añadir nueva tarifa', 'hwe-shipping' ),
				'new_item'           => __( 'Nueva tarifa', 'hwe-shipping' ),
				'edit_item'          => __( 'Editar tarifa', 'hwe-shipping' ),
				'view_item'          => __( 'Ver tarifa', 'hwe-shipping' ),
				'all_items'          => __( 'Tarifas de envío', 'hwe-shipping' ),
				'search_items'       => __( 'Buscar tarifas', 'hwe-shipping' ),
				'not_found'          => __( 'No se encontraron tarifas', 'hwe-shipping' ),
				'not_found_in_trash' => __( 'No hay tarifas en la papelera', 'hwe-shipping' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			// Ítem de primer nivel "Envío": las tarifas por ciudad son lo que más
			// se edita; los métodos (extras) cuelgan de él como submenú.
			'show_in_menu'        => true,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'has_archive'         => false,
			'hierarchical'        => false,
			'menu_position'       => 26,
			'menu_icon'           => 'dashicons-car',
			'supports'            => [ 'title' ],
		] );
	}
}
