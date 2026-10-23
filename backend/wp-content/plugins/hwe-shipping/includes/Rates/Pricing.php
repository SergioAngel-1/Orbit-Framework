<?php

namespace HWE\Shipping\Rates;

/**
 * Coste de una opción de entrega: la ÚNICA implementación del precio del envío. El navegador
 * nunca calcula ni firma un importe; el frontend no repite esta lógica.
 *
 * - Opción fija: su importe.
 * - Opción por distancia: `km × precio por km`, después se eleva al mínimo y se recorta al máximo
 *   (0 = sin límite). El resultado se redondea a los decimales de la moneda de WooCommerce.
 */
final class Pricing {

	/**
	 * Coste en la moneda de la tienda. `$km` es obligatorio para una opción por distancia; si falta,
	 * es un error de programación (el método de envío no debería llegar aquí sin distancia).
	 */
	public static function cost( DeliveryOption $option, ?float $km ): float {
		if ( null !== $option->amount() ) {
			return self::round( $option->amount() );
		}

		if ( null === $option->pricePerKm() || null === $km ) {
			throw new \LogicException( 'Una opción por distancia necesita precio por km y distancia.' );
		}

		$cost = self::round( $km * $option->pricePerKm() );
		if ( $option->minCost() > 0 && $cost < $option->minCost() ) {
			$cost = $option->minCost();
		}
		if ( $option->maxCost() > 0 && $cost > $option->maxCost() ) {
			$cost = $option->maxCost();
		}

		return self::round( $cost );
	}

	/** Redondea a los decimales de la moneda de WooCommerce (2 si no está cargado). */
	public static function round( float $amount ): float {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( $amount, $decimals );
	}
}
