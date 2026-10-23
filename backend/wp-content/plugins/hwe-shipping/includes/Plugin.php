<?php

namespace HWE\Shipping;

use HWE\Shipping\Admin\CityAssets;
use HWE\Shipping\Admin\CityErrors;
use HWE\Shipping\Admin\LegacyNotice;
use HWE\Shipping\Rates\Cities;

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
		}
		Save::register();
		RestApi::register();
		Revalidation::register();
		ShippingCost::register();
		FreeShipping::register();
		ReserveZone::register();
	}
}
