<?php

namespace HWE\Shipping;

/**
 * Extensión `cart.extensions.hwe_shipping` de la Store API (spec §8.3): qué pasa con el envío del destino
 * actual, para que la UI explique el motivo en vez de mostrar «sin tarifas».
 *
 * Es el estado del primer paquete del carrito, calculado por `ShippingStatus` (la misma decisión que usa el
 * método de envío, sin volver a decidir aquí): `mode`, `status` y, si es una distancia válida, `distance_km`,
 * `duration_min` y `fallback`. Nunca importes ni direcciones de referencia. Sin carrito o sin paquetes, el
 * estado es neutro (`none` / `needs_address`) y no rompe la respuesta.
 */
final class StoreApiExtension {

	public const NAMESPACE_ID = 'hwe_shipping';

	public static function register(): void {
		// Mismo criterio que `DeliveryPoint::register`: si WooCommerce ya cargó el bloque, registrar en el acto.
		if ( function_exists( 'did_action' ) && did_action( 'woocommerce_blocks_loaded' ) ) {
			self::registerStoreApi();
		} else {
			add_action( 'woocommerce_blocks_loaded', [ self::class, 'registerStoreApi' ] );
		}
	}

	public static function registerStoreApi(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data( [
			'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
			'namespace'       => self::NAMESPACE_ID,
			'data_callback'   => [ self::class, 'cartState' ],
			'schema_callback' => [ self::class, 'cartStateSchema' ],
			'schema_type'     => ARRAY_A,
		] );
	}

	/**
	 * Estado del envío del primer paquete del carrito de la sesión.
	 *
	 * @return array<string,mixed>
	 */
	public static function cartState(): array {
		$package = self::firstPackage();
		if ( null === $package ) {
			return [ 'mode' => 'none', 'status' => 'needs_address' ];
		}

		return ShippingStatus::forPackage( $package );
	}

	/** @return array<string,array<string,mixed>> */
	public static function cartStateSchema(): array {
		$field = static fn ( string $description, array $schema ): array => array_merge(
			[
				'description' => $description,
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
			$schema
		);

		return [
			'mode'         => $field( 'Cómo se calcula el envío del destino.', [
				'type' => 'string',
				'enum' => [ 'fixed', 'distance', 'reserve', 'none' ],
			] ),
			'status'       => $field( 'Si el destino tiene tarifas; cualquier valor distinto de ok significa sin tarifas.', [
				'type' => 'string',
				'enum' => [ 'ok', 'needs_address', 'needs_location', 'out_of_coverage' ],
			] ),
			'distance_km'  => $field( 'Distancia desde la dirección de referencia, en km (solo ciudades por distancia con estado ok).', [
				'type' => 'number',
			] ),
			'duration_min' => $field( 'Duración estimada del trayecto, en minutos (solo ciudades por distancia con estado ok).', [
				'type' => 'integer',
			] ),
			'fallback'     => $field( 'La distancia es una estimación en línea recta por respaldo.', [
				'type' => 'boolean',
			] ),
		];
	}

	/**
	 * Primer paquete del carrito (con el punto de entrega ya añadido por `woocommerce_cart_shipping_packages`),
	 * o null si no hay carrito ni paquetes. También lo usa `FreeShipping` para sus `rate_ids`.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function firstPackage(): ?array {
		$wc   = function_exists( 'WC' ) ? WC() : null;
		$cart = is_object( $wc ) && isset( $wc->cart ) ? $wc->cart : null;
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_shipping_packages' ) ) {
			return null;
		}

		$packages = $cart->get_shipping_packages();
		if ( ! is_array( $packages ) || [] === $packages ) {
			return null;
		}

		$first = $packages[ array_key_first( $packages ) ];

		return is_array( $first ) ? $first : null;
	}
}
