<?php

namespace HWE\Shipping\Distance;

/**
 * Ajustes de distancia leídos del Control Center, saneados y con su valor por defecto.
 *
 * - Proveedor y clave de mapas: grupo `integrations` (Integraciones → Mapas). La clave va cifrada
 *   en `SecretsStorage` y solo la lee el servidor: este objeto nunca la envía a ningún sitio.
 * - Método, modo de viaje, incremento y TTL: grupo `shipping` (Envío → Distancia).
 *
 * Sin Control Center (la clase no existe) o sin valor guardado, devuelve los valores por defecto.
 * Un valor fuera de rango se lleva al límite; un valor basura, al por defecto.
 */
final class Settings {

	public const DEFAULT_METHOD          = 'straight_line';
	public const DEFAULT_PROVIDER        = 'none';
	public const DEFAULT_TRAVEL_MODE     = 'driving';
	public const DEFAULT_INCREMENT_PCT   = 30;
	public const DEFAULT_CACHE_TTL_HOURS = 24;

	private const METHODS   = [ 'maps', 'straight_line' ];
	private const PROVIDERS = [ 'none', 'google_maps', 'openrouteservice' ];
	private const MODES     = [ 'driving', 'walking' ];

	/** `maps` (proveedor configurado) o `straight_line`. */
	public static function method(): string {
		return self::oneOf( self::METHODS, self::shipping( 'distance_method' ), self::DEFAULT_METHOD );
	}

	/** `none`, `google_maps` u `openrouteservice`. */
	public static function mapsProvider(): string {
		return self::oneOf( self::PROVIDERS, self::integration( 'maps_provider' ), self::DEFAULT_PROVIDER );
	}

	/** Clave del proveedor de mapas, sin espacios. Vacía si no hay ninguna guardada. */
	public static function mapsKey(): string {
		if ( ! class_exists( 'HWE\\ControlCenter\\SecretsStorage' ) ) {
			return '';
		}
		return trim( \HWE\ControlCenter\SecretsStorage::get( [ 'integrations', 'maps_api_key' ], '' ) );
	}

	/** Hay proveedor de pago elegido y clave. Sin ambos, el cálculo es en línea recta. */
	public static function mapsConfigured(): bool {
		return 'none' !== self::mapsProvider() && '' !== self::mapsKey();
	}

	/** `driving` o `walking`. */
	public static function travelMode(): string {
		return self::oneOf( self::MODES, self::shipping( 'distance_travel_mode' ), self::DEFAULT_TRAVEL_MODE );
	}

	/** Incremento sobre la línea recta, en % (0–200). */
	public static function incrementPct(): int {
		$value = self::shipping( 'distance_straight_line_increment' );
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_INCREMENT_PCT;
		}
		return (int) max( 0, min( 200, round( (float) $value ) ) );
	}

	/** Horas que vive en caché una distancia normal (mínimo 1). */
	public static function cacheTtlHours(): int {
		$value = self::shipping( 'distance_cache_ttl_hours' );
		if ( ! is_numeric( $value ) ) {
			return self::DEFAULT_CACHE_TTL_HOURS;
		}
		return (int) max( 1, round( (float) $value ) );
	}

	/** @param string[] $allowed */
	private static function oneOf( array $allowed, mixed $value, string $default ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $default;
	}

	/** Valor de `shipping.<clave>` o null si no hay Control Center o no hay valor guardado. */
	private static function shipping( string $key ): mixed {
		return self::storage( [ 'shipping', $key ] );
	}

	/** Valor de `integrations.<clave>` o null si no hay Control Center o no hay valor guardado. */
	private static function integration( string $key ): mixed {
		return self::storage( [ 'integrations', $key ] );
	}

	private static function storage( array $path ): mixed {
		if ( ! class_exists( 'HWE\\ControlCenter\\Storage' ) ) {
			return null;
		}
		$value = \HWE\ControlCenter\Storage::get( $path );
		return '' === $value ? null : $value;
	}
}
