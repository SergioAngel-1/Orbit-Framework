<?php

namespace HWE\Shipping;

use HWE\Shipping\Admin\CityAssets;
use HWE\Shipping\Admin\CityErrors;
use HWE\Shipping\Admin\LegacyNotice;
use HWE\Shipping\Admin\MapsAdminApi;
use HWE\Shipping\Admin\MapsNotices;
use HWE\Shipping\Admin\MapsTestType;
use HWE\Shipping\Admin\OrderShippingMeta;
use HWE\Shipping\Rates\Cities;
use HWE\Shipping\Rest\GeocodeApi;

/**
 * Punto de arranque del plugin. Engancha los subsistemas en el orden correcto.
 */
class Plugin {

	public static function boot(): void {
		add_action( 'init', array( PostTypes::class, 'register' ) );
		Cities::register();
		if ( is_admin() ) {
			MetaBoxes::register();
			AdminColumns::register();
			CityAssets::register();
			CityErrors::register();
			LegacyNotice::register();
			OrderShippingMeta::register();
		}
		Save::register();
		RestApi::register();
		// Las rutas de wp-admin son REST (fuera de `is_admin()`); los avisos y el tipo de campo
		// solo se pintan en admin, pero su registro es incondicional para no depender del orden.
		MapsAdminApi::register();
		MapsNotices::register();
		// El tipo de campo extiende una interfaz del Control Center: sin él (instalación a medias)
		// se omite en vez de intentar cargar una clase que no puede declararse.
		if ( interface_exists( \HWE\ControlCenter\FieldTypes\FieldType::class ) ) {
			MapsTestType::register();
		}
		// Geocodificación para el mapa del BFF: solo con el secreto interno (fuera de `is_admin()`).
		GeocodeApi::register();
		Revalidation::register();
		DeliveryPoint::register();
		// Un cierre, no `[CityRateMethod::class, …]`: la clase extiende WC_Shipping_Method, que puede no
		// estar cargado al arrancar (este plugin carga antes que WooCommerce); solo se toca al invocarlo.
		add_filter(
			'woocommerce_shipping_methods',
			static fn ( array $methods ): array => CityRateMethod::registerMethod( $methods )
		);
		ShippingCost::register();
		FreeShipping::register();
		StoreApiExtension::register();
		PublicConfig::register();
		SettingsSchema::register();
		ReserveZone::register();
	}
}
