<?php

namespace HWE\Shipping\Distance;

use HWE\Shipping\Rates\GeoPoint;

/**
 * Contrato de un proveedor de distancias y geocodificación (Google, OpenRouteService, línea
 * recta + Nominatim). Los errores salen como `WP_Error` con un código estable
 * (`provider_unauthorized`, `provider_rate_limited`, `provider_unavailable`, `route_unavailable`,
 * `geocoding_no_results`, `geocoding_backoff`); nunca el mensaje crudo del proveedor.
 *
 * La clave del proveedor no se pasa aquí: cada implementación la lee de los ajustes del servidor.
 */
interface Provider {

	/** Id estable del proveedor: `google_maps`, `openrouteservice`, `straight_line`… */
	public function id(): string;

	/** Distancia y duración por carretera (o línea recta, según el proveedor). `$mode`: driving|walking. */
	public function distance( GeoPoint $from, GeoPoint $to, string $mode ): DistanceResult|\WP_Error;

	/**
	 * Candidatos para una dirección, mejor primero. `$country` es el código ISO del país base y
	 * `$language` el idioma principal del sitio.
	 *
	 * @return GeocodeResult[]|\WP_Error
	 */
	public function geocode( string $query, string $country, string $language ): array|\WP_Error;

	/** Dirección legible para una coordenada. */
	public function reverse( GeoPoint $point, string $language ): GeocodeResult|\WP_Error;
}
