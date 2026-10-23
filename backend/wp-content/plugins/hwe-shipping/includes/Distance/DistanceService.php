<?php

namespace HWE\Shipping\Distance;

use HWE\Shipping\Rates\GeoPoint;
use HWE\Shipping\ShippingCost;

/**
 * Distancias y geocodificación con caché (transients, sobre la caché de objetos del framework).
 *
 * - `between()` nunca falla: caché → proveedor configurado → línea recta marcada como respaldo
 *   con el motivo. Un 0 nunca sale por error: los proveedores ya rechazan las respuestas absurdas.
 * - `geocode()` y `reverse()`: caché (7 días) → proveedor de mapas si hay clave → Nominatim. Los
 *   errores no se cachean.
 *
 * La clave del proveedor nunca entra en una clave de caché, en un log ni en un resultado: solo el
 * id del proveedor y el código del error.
 */
final class DistanceService {

	private const DISTANCE_PREFIX = 'hwe_shipping_dist_';
	private const GEO_PREFIX      = 'hwe_shipping_geo_';
	private const GEO_TTL         = 7 * DAY_IN_SECONDS;
	private const FALLBACK_TTL    = 15 * MINUTE_IN_SECONDS;
	private const DEFAULT_LANG    = 'es';
	private const NOMINATIM_ID    = 'nominatim';

	/**
	 * Proveedores por id. El filtro `hwe_shipping_distance_providers` puede añadir o sustituir
	 * proveedores; un valor que no sea `Provider` se descarta y se registra.
	 *
	 * @return array<string,Provider>
	 */
	public static function providers(): array {
		$providers = array(
			GoogleProvider::ID            => new GoogleProvider(),
			OpenRouteServiceProvider::ID  => new OpenRouteServiceProvider(),
			StraightLineProvider::ID      => new StraightLineProvider(),
		);

		$filtered = apply_filters( 'hwe_shipping_distance_providers', $providers );
		if ( ! is_array( $filtered ) ) {
			error_log( '[hwe] shipping: hwe_shipping_distance_providers debe devolver un array; se usan los proveedores del núcleo.' );
			return $providers;
		}

		$valid = array();
		foreach ( $filtered as $id => $provider ) {
			if ( is_string( $id ) && '' !== $id && $provider instanceof Provider ) {
				$valid[ $id ] = $provider;
				continue;
			}
			error_log( sprintf( '[hwe] shipping: proveedor de distancias descartado (id «%s»): debe implementar Provider.', (string) $id ) );
		}
		return $valid;
	}

	/** Distancia por carretera entre dos puntos, o respaldo en línea recta marcado. Nunca falla. */
	public static function between( GeoPoint $origin, GeoPoint $dest ): DistanceResult {
		$mode      = Settings::travelMode();
		$increment = Settings::incrementPct();
		$requested = self::requestedDistanceProvider();

		$key    = self::DISTANCE_PREFIX . md5( implode( '|', array( self::coords( $origin ), self::coords( $dest ), $requested, $mode, $increment ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			$restored = self::distanceFromArray( $cached );
			if ( null !== $restored ) {
				return $restored;
			}
		}

		$result = self::computeDistance( $origin, $dest, $requested, $mode, $increment );
		$ttl    = $result->fallback() ? self::FALLBACK_TTL : Settings::cacheTtlHours() * HOUR_IN_SECONDS;
		set_transient( $key, self::distanceToArray( $result ), $ttl );

		return $result;
	}

	/**
	 * Candidatos para una dirección escrita, mejor primero. Se normaliza con el paquete del país
	 * base antes de consultar.
	 *
	 * @return GeocodeResult[]|\WP_Error
	 */
	public static function geocode( string $query ): array|\WP_Error {
		$country    = ShippingCost::baseCountry();
		$language   = self::language();
		$normalized = AddressNormalizer::forGeocoding( $query, self::pack( $country ) );
		if ( '' === $normalized ) {
			return new \WP_Error( 'geocoding_no_results', 'La dirección está vacía.' );
		}

		$key    = self::GEO_PREFIX . md5( implode( '|', array( 'search', $country, $language, $normalized, self::geocodingProvider() ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			$restored = self::geocodesFromArray( $cached );
			if ( array() !== $restored ) {
				return $restored;
			}
		}

		$results = self::geocodeChain( $normalized, $country, $language );
		if ( is_array( $results ) ) {
			set_transient( $key, array_map( self::geocodeToArray( ... ), $results ), self::GEO_TTL );
		}

		return $results;
	}

	/** Dirección legible para un punto, con la misma cadena de proveedores que `geocode()`. */
	public static function reverse( GeoPoint $point ): GeocodeResult|\WP_Error {
		$language = self::language();

		$key    = self::GEO_PREFIX . md5( implode( '|', array( 'reverse', self::coords( $point ), $language, self::geocodingProvider() ) ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			$restored = self::geocodeFromArray( $cached );
			if ( null !== $restored ) {
				return $restored;
			}
		}

		$result = self::reverseChain( $point, $language );
		if ( $result instanceof GeocodeResult ) {
			set_transient( $key, self::geocodeToArray( $result ), self::GEO_TTL );
		}

		return $result;
	}

	/** Proveedor que pide el método de distancia: `straight_line`, o el de mapas si el método es `maps`. */
	private static function requestedDistanceProvider(): string {
		return 'maps' === Settings::method() ? Settings::mapsProvider() : StraightLineProvider::ID;
	}

	/** Proveedor que atiende la geocodificación: el de mapas si hay clave, o Nominatim. */
	private static function geocodingProvider(): string {
		return Settings::mapsConfigured() ? Settings::mapsProvider() : self::NOMINATIM_ID;
	}

	private static function computeDistance( GeoPoint $origin, GeoPoint $dest, string $requested, string $mode, int $increment ): DistanceResult {
		if ( StraightLineProvider::ID === $requested ) {
			return Haversine::estimate( $origin, $dest, $increment, $mode );
		}

		if ( ! Settings::mapsConfigured() ) {
			error_log( '[hwe] shipping: el método es «maps» sin proveedor o clave válidos: la distancia se calcula en línea recta.' );
			return self::fallback( $origin, $dest, $increment, $mode, 'not_configured' );
		}

		$provider = self::providers()[ $requested ] ?? null;
		if ( null === $provider ) {
			error_log( sprintf( '[hwe] shipping: el proveedor de distancias «%s» no está disponible; respaldo en línea recta.', $requested ) );
			return self::fallback( $origin, $dest, $increment, $mode, 'provider_unavailable' );
		}

		$result = self::guarded( $requested, static fn (): mixed => $provider->distance( $origin, $dest, $mode ) );
		if ( $result instanceof DistanceResult ) {
			return $result;
		}

		$code = $result instanceof \WP_Error ? (string) $result->get_error_code() : 'provider_unavailable';
		error_log( sprintf( '[hwe] shipping: distancia con «%s» no disponible (%s); respaldo en línea recta.', $requested, $code ) );
		return self::fallback( $origin, $dest, $increment, $mode, $code );
	}

	private static function fallback( GeoPoint $origin, GeoPoint $dest, int $increment, string $mode, string $reason ): DistanceResult {
		$line = Haversine::estimate( $origin, $dest, $increment, $mode );
		return new DistanceResult( $line->km(), $line->durationMin(), StraightLineProvider::ID, true, $reason );
	}

	/** @return GeocodeResult[]|\WP_Error */
	private static function geocodeChain( string $query, string $country, string $language ): array|\WP_Error {
		if ( Settings::mapsConfigured() ) {
			$id      = Settings::mapsProvider();
			$result  = self::guarded( $id, static fn (): mixed => ( self::providers()[ $id ] ?? null )?->geocode( $query, $country, $language ) ?? self::missing() );
			$results = is_array( $result ) ? self::stamp( $result, $id ) : array();
			if ( array() !== $results ) {
				return $results;
			}
			self::logGeocodingFallback( $id, $result );
		}

		$fallback = ( new StraightLineProvider() )->geocode( $query, $country, $language );
		return is_array( $fallback ) ? self::stamp( $fallback, self::NOMINATIM_ID ) : $fallback;
	}

	private static function reverseChain( GeoPoint $point, string $language ): GeocodeResult|\WP_Error {
		if ( Settings::mapsConfigured() ) {
			$id     = Settings::mapsProvider();
			$result = self::guarded( $id, static fn (): mixed => ( self::providers()[ $id ] ?? null )?->reverse( $point, $language ) ?? self::missing() );
			if ( $result instanceof GeocodeResult ) {
				return $result->withProvider( $id );
			}
			self::logGeocodingFallback( $id, $result );
		}

		$fallback = ( new StraightLineProvider() )->reverse( $point, $language );
		return $fallback instanceof GeocodeResult ? $fallback->withProvider( self::NOMINATIM_ID ) : $fallback;
	}

	/**
	 * Marca cada candidato con el proveedor que respondió. Un resultado que no sea `GeocodeResult`
	 * (un proveedor de un filtro puede devolver cualquier cosa) se descarta en vez de romper.
	 *
	 * @param array<mixed> $results
	 * @return GeocodeResult[]
	 */
	private static function stamp( array $results, string $provider ): array {
		$stamped = array();
		foreach ( $results as $result ) {
			if ( $result instanceof GeocodeResult ) {
				$stamped[] = $result->withProvider( $provider );
			}
		}
		return $stamped;
	}

	/**
	 * Ejecuta una llamada a un proveedor. Una excepción suya no rompe el cálculo: se convierte en un
	 * `provider_unavailable` registrado por clase (nunca por mensaje, que podría llevar datos).
	 *
	 * @param callable(): mixed $call
	 */
	private static function guarded( string $id, callable $call ): mixed {
		try {
			return $call();
		} catch ( \Throwable $error ) {
			error_log( sprintf( '[hwe] shipping: el proveedor «%s» lanzó %s; se descarta su respuesta.', $id, $error::class ) );
			return new \WP_Error( 'provider_unavailable', 'El proveedor de mapas lanzó una excepción.' );
		}
	}

	/** Sustituto cuando el proveedor configurado no está registrado (lo quitó un filtro). */
	private static function missing(): \WP_Error {
		return new \WP_Error( 'provider_unavailable', 'El proveedor de mapas no está registrado.' );
	}

	/** Un fallo del proveedor de mapas se registra antes de caer a Nominatim. */
	private static function logGeocodingFallback( string $id, mixed $result ): void {
		$code = $result instanceof \WP_Error ? (string) $result->get_error_code() : 'geocoding_no_results';
		error_log( sprintf( '[hwe] shipping: geocodificación con «%s» no disponible (%s); se usa Nominatim.', $id, $code ) );
	}

	/** Idioma principal del sitio (`brand.locale`), o el del WordPress, en minúsculas. */
	public static function language(): string {
		$locale = class_exists( 'HWE\\ControlCenter\\Storage' ) ? \HWE\ControlCenter\Storage::get( array( 'brand', 'locale' ) ) : null;
		if ( ! is_string( $locale ) || '' === trim( $locale ) ) {
			$locale = get_locale();
		}
		$primary = strtolower( (string) preg_split( '/[-_]/', trim( $locale ) )[0] );

		return '' !== $primary ? $primary : self::DEFAULT_LANG;
	}

	/** Reglas de geocodificación del país: el paquete de `hwe-geo`, o ninguna si no está cargado. */
	private static function pack( string $country ): array {
		if ( function_exists( 'hwe_geo_resolve' ) ) {
			return hwe_geo_resolve( $country )['pack'];
		}
		return array( 'geocoding' => array( 'abbreviations' => array(), 'drop_tokens' => array() ) );
	}

	private static function coords( GeoPoint $point ): string {
		return sprintf( '%.4F,%.4F', $point->lat(), $point->lng() );
	}

	/** @return array{km:float,durationMin:int,provider:string,fallback:bool,fallbackReason:string} */
	private static function distanceToArray( DistanceResult $result ): array {
		return array(
			'km'             => $result->km(),
			'durationMin'    => $result->durationMin(),
			'provider'       => $result->provider(),
			'fallback'       => $result->fallback(),
			'fallbackReason' => $result->fallbackReason(),
		);
	}

	/** @param array<mixed> $data */
	private static function distanceFromArray( array $data ): ?DistanceResult {
		try {
			return new DistanceResult(
				(float) ( $data['km'] ?? -1 ),
				(int) ( $data['durationMin'] ?? -1 ),
				(string) ( $data['provider'] ?? '' ),
				(bool) ( $data['fallback'] ?? false ),
				(string) ( $data['fallbackReason'] ?? '' )
			);
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}

	/** @return array{lat:float,lng:float,label:string,precision:string,provider:string} */
	private static function geocodeToArray( GeocodeResult $result ): array {
		return array(
			'lat'       => $result->point()->lat(),
			'lng'       => $result->point()->lng(),
			'label'     => $result->label(),
			'precision' => $result->precision(),
			'provider'  => $result->provider(),
		);
	}

	/** @param array<mixed> $data */
	private static function geocodeFromArray( array $data ): ?GeocodeResult {
		$point = GeoPoint::tryFrom( $data['lat'] ?? null, $data['lng'] ?? null );
		if ( null === $point ) {
			return null;
		}
		return new GeocodeResult(
			$point,
			(string) ( $data['label'] ?? '' ),
			(string) ( $data['precision'] ?? '' ),
			(string) ( $data['provider'] ?? '' )
		);
	}

	/**
	 * @param array<mixed> $rows
	 * @return GeocodeResult[] Vacía si alguna fila no es válida (entonces la caché no se usa).
	 */
	private static function geocodesFromArray( array $rows ): array {
		$results = array();
		foreach ( $rows as $row ) {
			$result = is_array( $row ) ? self::geocodeFromArray( $row ) : null;
			if ( null === $result ) {
				return array();
			}
			$results[] = $result;
		}
		return $results;
	}
}
