<?php

namespace HWE\Shipping\Admin;

use HWE\ControlCenter\FieldTypes\FieldType;

/**
 * Tipo de campo del Control Center para el botón «Probar conexión» del bloque «Distancia».
 *
 * El campo no guarda nada (`external`): el control solo lleva una dirección de prueba editable y un
 * botón que llama a la ruta de wp-admin (`assets/maps-test.js`, cargado solo en la página del
 * panel). Sin JS el formulario sigue guardando igual: el control no tiene `name`, así que no viaja
 * con el POST de configuración y no hay valor que perder.
 *
 * El texto del botón y el estado accesible los pone el propio control; el resultado (correcto, clave
 * rechazada, API no habilitada, cuota, red…) lo anuncia `aria-live`.
 */
class MapsTestType implements FieldType {

	public const ID = 'maps_connection_test';

	public static function register(): void {
		// Sin Control Center no hay tipos de campo que extender (el plugin funciona igual).
		if ( ! interface_exists( FieldType::class ) ) {
			return;
		}
		add_filter( 'hwe_control_center_field_types', array( self::class, 'addType' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * @param array<string,FieldType> $types Tipos ya registrados.
	 * @return array<string,FieldType>
	 */
	public static function addType( array $types ): array {
		$types[ self::ID ] = new self();
		return $types;
	}

	public function id(): string {
		return self::ID;
	}

	public function persistence(): string {
		return self::EXTERNAL;
	}

	/** No se persiste nunca: el botón no describe configuración. */
	public function sanitize( array $field, mixed $raw ): mixed {
		return self::SKIP;
	}

	public function render( array $field, string $id, string $name, mixed $value, array $path, array $context = array() ): string {
		$addressId = esc_attr( $id );
		$buttonId  = esc_attr( $id . '-button' );
		$statusId  = esc_attr( $id . '-status' );
		$button    = esc_html__( 'Probar conexión', 'hwe-shipping' );
		$note      = esc_html__( 'Hace una llamada real al proveedor de Integraciones → Mapas con esta dirección. No se guarda nada.', 'hwe-shipping' );

		return '<div class="hwe-maps-test" data-hwe-maps-test>'
			. '<p class="hwe-maps-test-controls">'
			. '<input type="text" class="regular-text" id="' . $addressId . '" data-hwe-maps-test-address autocomplete="off" aria-label="' . esc_attr( __( 'Dirección de prueba', 'hwe-shipping' ) ) . '" placeholder="' . esc_attr( __( 'Calle y ciudad', 'hwe-shipping' ) ) . '"> '
			. '<button type="button" class="button" id="' . $buttonId . '" data-hwe-maps-test-button>' . $button . '</button>'
			. '</p>'
			. '<p class="description">' . $note . '</p>'
			. '<p class="hwe-maps-test-result" id="' . $statusId . '" role="status" aria-live="polite" data-hwe-maps-test-result></p>'
			. '</div>';
	}

	/** El script del botón, solo en la página del Control Center. */
	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_hwe-config' !== ( $screen->id ?? '' ) ) {
			return;
		}

		$url = plugins_url( 'assets', HWE_SHIPPING_DIR . '/hwe-shipping.php' );
		wp_enqueue_style(
			'hwe-shipping-maps-test',
			$url . '/maps-test.css',
			array(),
			self::version( 'assets/maps-test.css' )
		);
		wp_enqueue_script(
			'hwe-shipping-maps-test',
			$url . '/maps-test.js',
			array( 'wp-api-fetch' ),
			self::version( 'assets/maps-test.js' ),
			true
		);
	}

	/** Versión para cache-busting: la del plugin más la fecha del archivo. */
	private static function version( string $relPath ): string {
		$file = HWE_SHIPPING_DIR . '/' . $relPath;
		$base = defined( 'HWE_SHIPPING_VERSION' ) ? HWE_SHIPPING_VERSION : '2.0.0';
		$time = is_readable( $file ) ? filemtime( $file ) : false;
		return $time ? $base . '.' . $time : $base;
	}
}
