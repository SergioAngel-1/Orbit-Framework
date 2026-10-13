<?php

namespace HWE\Landings;

use HWE\Landings\FieldTypes\LandingRegistryType;
use HWE\Landings\FieldTypes\RegistryTypeType;

/**
 * Cableado del módulo: esquema del Control Center, tipos de campo, locaciones de banner,
 * revalidación y los assets del editor del registro.
 */
final class Plugin {

	public static function register(): void {
		add_filter( 'hwe_control_center_schema', array( ContentSchema::class, 'inject' ), 20 );
		add_filter( 'hwe_control_center_field_types', array( self::class, 'fieldTypes' ) );
		add_filter( 'hwe_banners_locations', array( BannerLocations::class, 'add' ) );
		Revalidation::register();
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * @param mixed $types
	 * @return mixed
	 */
	public static function fieldTypes( $types ) {
		if ( ! is_array( $types ) ) {
			return $types;
		}
		$types['landing_registry'] = new LandingRegistryType();
		$types['registry_type']    = new RegistryTypeType();
		return $types;
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_hwe-config' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'hwe-landings-admin', HWE_LANDINGS_URL . 'assets/admin.js', array(), HWE_LANDINGS_VERSION, true );
		wp_enqueue_style( 'hwe-landings-admin', HWE_LANDINGS_URL . 'assets/admin.css', array(), HWE_LANDINGS_VERSION );
	}
}
