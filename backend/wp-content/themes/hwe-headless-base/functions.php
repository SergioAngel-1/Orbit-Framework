<?php
/**
 * Sin funcionalidad de tema real (headless). Solo declara el soporte mínimo
 * que WooCommerce/WPGraphQL esperan de CUALQUIER tema activo: sin esto,
 * WooCommerce muestra un aviso de "tema no compatible" en wp-admin y las
 * imágenes destacadas no quedan disponibles para `featuredImage` en GraphQL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'woocommerce' );
		add_theme_support( 'title-tag' );
	}
);
