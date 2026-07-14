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

		// Locations de menú para el frontend headless (WPGraphQL las expone
		// vía menuItems(where:{location:…})). Convención: sin sufijo = locale
		// por defecto (es); sufijo _en = versión en inglés del menú.
		register_nav_menus(
			[
				'primary'    => 'Navegación principal',
				'primary_en' => 'Navegación principal (EN)',
				'footer'     => 'Pie de página',
				'footer_en'  => 'Pie de página (EN)',
			]
		);
	}
);
