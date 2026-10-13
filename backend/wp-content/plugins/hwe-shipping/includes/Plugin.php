<?php

namespace HWE\Shipping;

/**
 * Punto de arranque del plugin. Engancha los subsistemas en el orden correcto.
 */
class Plugin {

	public static function boot(): void {
		add_action( 'init', array( PostTypes::class, 'register' ) );
		if ( is_admin() ) {
			MetaBoxes::register();
			AdminColumns::register();
		}
		Save::register();
		RestApi::register();
		Revalidation::register();
		ShippingCost::register();
		FreeShipping::register();
		ReserveZone::register();
	}
}
