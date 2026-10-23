<?php

namespace HWE\Shipping\Rates;

/**
 * Coordenada geográfica (WGS84) inmutable. No existe una coordenada inválida: el constructor
 * rechaza lo que no son números finitos dentro de rango, y `tryFrom` acepta además cadenas
 * numéricas (lo que llega de un campo de formulario o de una meta).
 */
final class GeoPoint {

	private float $lat;
	private float $lng;

	public function __construct( float $lat, float $lng ) {
		if ( ! self::isValid( $lat, $lng ) ) {
			throw new \InvalidArgumentException( 'Coordenada fuera de rango.' );
		}
		$this->lat = $lat;
		$this->lng = $lng;
	}

	/**
	 * Null si no son números finitos dentro de [-90,90]/[-180,180] o si son (0,0), que casi
	 * siempre es un campo vacío convertido en número.
	 */
	public static function tryFrom( mixed $lat, mixed $lng ): ?self {
		if ( ! self::isNumeric( $lat ) || ! self::isNumeric( $lng ) ) {
			return null;
		}
		$lat = (float) $lat;
		$lng = (float) $lng;
		if ( ! self::isValid( $lat, $lng ) || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}
		return new self( $lat, $lng );
	}

	public function lat(): float {
		return $this->lat;
	}

	public function lng(): float {
		return $this->lng;
	}

	private static function isValid( float $lat, float $lng ): bool {
		return is_finite( $lat ) && is_finite( $lng ) && abs( $lat ) <= 90 && abs( $lng ) <= 180;
	}

	private static function isNumeric( mixed $value ): bool {
		if ( is_int( $value ) || is_float( $value ) ) {
			return true;
		}
		return is_string( $value ) && is_numeric( $value );
	}
}
