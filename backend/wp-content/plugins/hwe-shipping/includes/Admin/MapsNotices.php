<?php

namespace HWE\Shipping\Admin;

use HWE\ControlCenter\SecretsStorage;
use HWE\Shipping\Distance\Settings;
use HWE\Shipping\PostTypes;

/**
 * Avisos de wp-admin de la configuración de mapas:
 *
 *  - el método de distancia es «Mapas» pero falta el proveedor o la clave: las distancias se
 *    calcularán en línea recta (degradación visible, no silenciosa);
 *  - hay una clave guardada que no se puede descifrar (cambió la clave de cifrado del sitio, se
 *    importó de otro servidor…): el proveedor se queda sin clave aunque en el panel parezca que hay.
 *
 * Solo en las pantallas de WooCommerce, del Control Center y del editor de ciudades, y solo para
 * quien gestiona la tienda. El texto nunca incluye la clave ni parte de ella.
 */
class MapsNotices {

	private const SECRETS_OPTION = 'hwe_config_secrets';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::isRelevantScreen() ) {
			return;
		}

		$notices = array();
		if ( 'maps' === Settings::method() && ! Settings::mapsConfigured() ) {
			$notices[] = __( 'El método de distancia es «Mapas», pero no hay un proveedor y una clave válidos en Integraciones → Mapas: las distancias se calcularán en línea recta.', 'hwe-shipping' );
		}
		if ( self::keyUndecryptable() ) {
			$notices[] = __( 'La clave de mapas guardada no se puede descifrar (¿cambió la clave de cifrado del sitio?). Vuelve a guardarla en Integraciones → Mapas.', 'hwe-shipping' );
		}

		foreach ( $notices as $notice ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
		}
	}

	/**
	 * Hay un valor guardado para `integrations.maps_api_key` y no descifra con la clave local.
	 *
	 * `SecretsStorage` trata un secreto ilegible como vacío (falla cerrado), así que sin este aviso
	 * la tienda se quedaría calculando en línea recta sin decir por qué.
	 */
	public static function keyUndecryptable(): bool {
		$stored = get_option( self::SECRETS_OPTION, array() );
		$value  = is_array( $stored ) && is_array( $stored['integrations'] ?? null ) ? ( $stored['integrations']['maps_api_key'] ?? '' ) : '';
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return false;
		}
		if ( ! class_exists( SecretsStorage::class ) ) {
			return false;
		}

		return null === SecretsStorage::decryptImported( array( 'integrations' => array( 'maps_api_key' => $value ) ) );
	}

	/** Pantallas donde el aviso aporta algo: WooCommerce, Control Center y editor de ciudades. */
	private static function isRelevantScreen(): bool {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		$id       = (string) ( $screen->id ?? '' );
		$postType = (string) ( $screen->post_type ?? '' );

		return str_starts_with( $id, 'woocommerce_page_' )
			|| 'toplevel_page_hwe-config' === $id
			|| PostTypes::RATE_SLUG === $postType;
	}
}
