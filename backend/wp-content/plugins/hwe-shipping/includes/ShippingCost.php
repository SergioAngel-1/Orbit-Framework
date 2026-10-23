<?php

namespace HWE\Shipping;

use HWE\Shipping\Rates\Cities;

/**
 * Utilidades de envío compartidas y el envío gratis del CUPÓN.
 *
 * Honra la casilla nativa «Permitir envío gratuito» del CUPÓN: con un cupón aplicado que la marca,
 * TODAS las tarifas del paquete quedan a 0. Las tarifas de ciudad las produce el método de envío
 * del plugin; aquí queda la normalización de nombres de ciudad, el país base de la tienda y la
 * invalidación de cachés.
 */
class ShippingCost {

	public static function register(): void {
		// Después de la regla de envío gratis (25): el cupón gana sobre cualquier tarifa.
		add_filter( 'woocommerce_package_rates', [ self::class, 'applyCouponFreeShipping' ], 30, 2 );
	}

	/**
	 * Envío a 0 si el carrito lleva un cupón con «Permitir envío gratuito».
	 * Los cupones del paquete ya pasaron la validación al aplicarse; WooCommerce
	 * los incluye en el hash del paquete, así que aplicar o quitar el cupón
	 * recalcula las tarifas.
	 *
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array{applied_coupons?:string[]} $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public static function applyCouponFreeShipping( array $rates, array $package ): array {
		if ( ! self::hasFreeShippingCoupon( (array) ( $package['applied_coupons'] ?? [] ) ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			$rate->set_cost( 0 );
			$rate->set_taxes( [] );
		}

		return $rates;
	}

	/** @param string[] $codes */
	public static function hasFreeShippingCoupon( array $codes ): bool {
		foreach ( $codes as $code ) {
			$coupon = new \WC_Coupon( (string) $code );
			if ( $coupon->get_id() && $coupon->get_free_shipping() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * País base de la tienda: `ecommerce.country` del Control Center (con el valor por defecto
	 * del esquema si no se ha guardado). Sin Control Center, el país base de WooCommerce.
	 */
	public static function baseCountry(): string {
		if ( function_exists( 'hwe_geo_store_country' ) ) {
			$country = hwe_geo_store_country();
			if ( '' !== $country ) {
				return $country;
			}
		}
		return strtoupper( (string) ( function_exists( 'wc_get_base_location' ) ? ( wc_get_base_location()['country'] ?? '' ) : '' ) );
	}

	/** Minúsculas, sin tildes, espacios colapsados. Es la clave con la que casan las ciudades. */
	public static function normalizeCity( string $city ): string {
		$plain = remove_accents( $city );
		$plain = strtolower( trim( $plain ) );
		return preg_replace( '/\s+/', ' ', $plain ) ?? $plain;
	}

	/** Invalida las ciudades y la versión del transient de envíos de WooCommerce. */
	public static function clearCache(): void {
		Cities::clearCache();
	}
}
