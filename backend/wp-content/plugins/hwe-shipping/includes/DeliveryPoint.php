<?php

namespace HWE\Shipping;

use HWE\Shipping\Rates\GeoPoint;

/**
 * Punto de entrega del cliente (el pin del mapa) en la sesión de WooCommerce.
 *
 * El navegador no calcula ni firma nada: declara el pin por la extensión `hwe_shipping` de la
 * Store API (`cart/extensions`) junto a la dirección con la que lo marcó. Aquí se valida la
 * coordenada y se guarda en la sesión con una HUELLA de calle + ciudad + país. El pin solo vale
 * mientras esa huella coincida con el destino del paquete: si el cliente cambia la calle o la
 * ciudad, la huella deja de coincidir y el punto se ignora (el carrito pasa a `needs_location`),
 * en vez de reutilizar una ubicación de otra dirección. Mayúsculas, tildes y espacios de más no
 * cambian la huella.
 *
 * Las coordenadas NO forman parte de la huella: identifica la dirección, no el pin; mover el pin
 * dentro de la misma dirección actualiza el punto sin invalidarlo.
 *
 * `woocommerce_cart_shipping_packages` copia el punto vigente (`destination['hwe_point']`) al
 * paquete para que el hash de WooCommerce cambie al mover el pin y no se reutilicen tarifas
 * calculadas para otra ubicación.
 *
 * El método de envío consume el punto con `forDestination()`; el update callback nunca calcula
 * precios, solo guarda lo declarado.
 */
final class DeliveryPoint {

	/** Namespace de la extensión de la Store API. */
	public const NAMESPACE_ID = 'hwe_shipping';

	/** Clave de la sesión de WooCommerce con el punto y su huella. */
	public const SESSION_KEY = 'hwe_shipping_delivery_point';

	/** Código de error de la Store API para una coordenada o una calle inválida. */
	public const ERROR_INVALID_POINT = 'hwe_shipping_invalid_point';

	/** Código de error de la Store API para una acción desconocida. */
	public const ERROR_INVALID_ACTION = 'hwe_shipping_invalid_action';

	/** Engancha el update callback de la Store API y el filtro de paquetes. */
	public static function register(): void {
		// `Plugin::boot()` corre al cargar el plugin; si WooCommerce ya disparó
		// `woocommerce_blocks_loaded` (orden de plugins), hay que registrarse en el acto.
		if ( function_exists( 'did_action' ) && did_action( 'woocommerce_blocks_loaded' ) ) {
			self::registerStoreApi();
		} else {
			add_action( 'woocommerce_blocks_loaded', [ self::class, 'registerStoreApi' ] );
		}
		add_filter( 'woocommerce_cart_shipping_packages', [ self::class, 'addPointToPackages' ] );
	}

	/**
	 * Registra el update callback del namespace `hwe_shipping`. La extensión de lectura
	 * (`extensions.hwe_shipping`) la añade el estado del envío; aquí solo se recibe el pin.
	 */
	public static function registerStoreApi(): void {
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				[
					'namespace' => self::NAMESPACE_ID,
					'callback'  => [ self::class, 'updatePoint' ],
				]
			);
		}
	}

	/**
	 * Update callback de la Store API: `{ action: 'set_point', lat, lng, address_1, city, country }`
	 * o `{ action: 'clear_point' }`. Sin sesión de WooCommerce no rompe: solo se registra.
	 *
	 * @param mixed $data Datos del endpoint `cart/extensions`.
	 * @throws \Throwable Cuando la acción, la calle o la coordenada no son válidas.
	 */
	public static function updatePoint( mixed $data ): void {
		$payload = is_array( $data ) ? $data : [];
		$action  = isset( $payload['action'] ) && is_string( $payload['action'] )
			? strtolower( trim( $payload['action'] ) )
			: '';

		if ( 'clear_point' === $action ) {
			self::store( null );
			return;
		}

		if ( 'set_point' !== $action ) {
			throw self::error(
				self::ERROR_INVALID_ACTION,
				'La acción del punto de entrega no es válida.'
			);
		}

		$point   = GeoPoint::tryFrom( $payload['lat'] ?? null, $payload['lng'] ?? null );
		$address = self::cleanText( $payload['address_1'] ?? null );

		if ( null === $point || '' === $address ) {
			throw self::error(
				self::ERROR_INVALID_POINT,
				'El punto de entrega no es válido: se necesitan una calle y una coordenada dentro de rango.'
			);
		}

		self::store(
			[
				'lat'         => $point->lat(),
				'lng'         => $point->lng(),
				'fingerprint' => self::fingerprint(
					[
						'address_1' => $address,
						'city'      => self::cleanText( $payload['city'] ?? null ),
						'country'   => self::cleanText( $payload['country'] ?? null ),
					]
				),
			]
		);
	}

	/**
	 * Huella de una dirección de destino: calle, ciudad normalizada y país, todo en minúsculas,
	 * sin tildes y con los espacios colapsados. No incluye coordenadas.
	 *
	 * @param array<string,mixed> $destination Destino del paquete de WooCommerce.
	 */
	public static function fingerprint( array $destination ): string {
		$address = self::normalize( self::text( $destination['address_1'] ?? '' ) );
		$city    = ShippingCost::normalizeCity( self::text( $destination['city'] ?? '' ) );
		$country = self::normalize( self::text( $destination['country'] ?? '' ) );

		return $address . '|' . $city . '|' . $country;
	}

	/**
	 * Punto vigente para ese destino, o `null` si no hay sesión, no hay punto o su huella ya no
	 * coincide con la dirección (calle o ciudad cambiadas). Una coordenada corrupta en la sesión
	 * también se ignora y se registra.
	 *
	 * @param array<string,mixed> $destination Destino del paquete de WooCommerce.
	 */
	public static function forDestination( array $destination ): ?GeoPoint {
		$state = self::state();
		if ( null === $state ) {
			return null;
		}

		$stored = isset( $state['fingerprint'] ) && is_string( $state['fingerprint'] )
			? $state['fingerprint']
			: '';
		if ( '' === $stored || ! hash_equals( $stored, self::fingerprint( $destination ) ) ) {
			return null;
		}

		$point = GeoPoint::tryFrom( $state['lat'] ?? null, $state['lng'] ?? null );
		if ( null === $point ) {
			self::log( 'el punto guardado en la sesión no es válido; se ignora.' );
			return null;
		}

		return $point;
	}

	/**
	 * Añade el punto vigente al destino de cada paquete. Con la huella cambiada o sin punto, la
	 * clave no se añade (y se retira si venía de una pasada anterior): el hash del paquete cambia
	 * con el pin y WooCommerce no reutiliza tarifas de otra ubicación.
	 *
	 * @param array<int,array<string,mixed>> $packages Paquetes de `woocommerce_cart_shipping_packages`.
	 * @return array<int,array<string,mixed>>
	 */
	public static function addPointToPackages( array $packages ): array {
		foreach ( $packages as $index => $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}

			$destination = isset( $package['destination'] ) && is_array( $package['destination'] )
				? $package['destination']
				: [];
			$point       = self::forDestination( $destination );

			if ( null === $point ) {
				unset( $packages[ $index ]['destination']['hwe_point'] );
				continue;
			}

			$packages[ $index ]['destination']['hwe_point'] = [
				'lat' => $point->lat(),
				'lng' => $point->lng(),
			];
		}

		return $packages;
	}

	// ------------------------------------------------------------------------
	//  Sesión y normalización
	// ------------------------------------------------------------------------

	/**
	 * Guarda el estado del punto (o lo borra con `null`). Sin sesión solo registra: la petición
	 * de la Store API no debe caerse por no poder guardar una preferencia.
	 *
	 * @param array{lat:float,lng:float,fingerprint:string}|null $state
	 */
	private static function store( ?array $state ): void {
		$session = self::session();
		if ( null === $session ) {
			self::log( 'no hay sesión de WooCommerce para guardar el punto de entrega.' );
			return;
		}

		$session->set( self::SESSION_KEY, $state );
	}

	/** @return array<string,mixed>|null */
	private static function state(): ?array {
		$session = self::session();
		if ( null === $session ) {
			return null;
		}

		$value = $session->get( self::SESSION_KEY );

		return is_array( $value ) ? $value : null;
	}

	/** Sesión de WooCommerce, o `null` fuera de una petición con sesión. */
	private static function session(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
			return null;
		}
		if ( ! method_exists( $wc->session, 'get' ) || ! method_exists( $wc->session, 'set' ) ) {
			return null;
		}

		return $wc->session;
	}

	/** Minúsculas, sin tildes y con los espacios colapsados (misma forma que `normalizeCity`). */
	private static function normalize( string $value ): string {
		$plain = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
		$plain = strtolower( trim( $plain ) );

		return preg_replace( '/\s+/', ' ', $plain ) ?? $plain;
	}

	/** Texto saneado de un campo del destino; cualquier cosa que no sea escalar cuenta como vacío. */
	private static function cleanText( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = (string) $value;

		return function_exists( 'sanitize_text_field' ) ? trim( sanitize_text_field( $text ) ) : trim( $text );
	}

	/** Texto de un valor escalar del destino; vacío si no lo es. */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Error de la Store API (o `RuntimeException` si WooCommerce no está cargado). */
	private static function error( string $code, string $message ): \Throwable {
		$class = 'Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( class_exists( $class ) ) {
			return new $class( $code, $message, 400 );
		}

		return new \RuntimeException( $message );
	}

	private static function log( string $message ): void {
		error_log( '[hwe] shipping: ' . $message );
	}
}
