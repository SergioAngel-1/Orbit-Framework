<?php

namespace HWE\Shipping\Distance;

/**
 * Peticiones a los proveedores de mapas (Google, OpenRouteService) y clasificación de sus errores.
 *
 * - Una respuesta que no es 200 o que trae `error` se traduce a un código estable
 *   (`provider_unauthorized`, `provider_rate_limited`, `provider_unavailable`); el mensaje crudo del
 *   proveedor no sale de aquí.
 * - Un 200 con cuerpo que no es JSON es `provider_unavailable`, nunca un resultado vacío.
 * - La clave la pone quien llama, en una cabecera. Este helper no la toca ni la registra.
 *
 * Los errores llevan el estado HTTP en `data.status` y, cuando el proveedor lo da en su cuerpo
 * estructurado, el motivo corto en `data.reason` (p. ej. el `SERVICE_DISABLED` de Google). Ni el
 * mensaje crudo ni la clave salen de aquí; el motivo sirve para distinguir «clave rechazada» de
 * «API no habilitada» en la prueba de conexión de wp-admin.
 */
final class ProviderHttp {

	private const TIMEOUT = 15;

	/**
	 * @param array<string,string> $headers
	 * @return array{status:int,body:array<mixed>}|\WP_Error
	 */
	public static function get( string $url, array $headers ): array|\WP_Error {
		return self::decode(
			wp_remote_get(
				$url,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => $headers + array( 'Accept' => 'application/json' ),
				)
			)
		);
	}

	/**
	 * @param array<string,string> $headers
	 * @param array<mixed>        $json
	 * @return array{status:int,body:array<mixed>}|\WP_Error
	 */
	public static function post( string $url, array $headers, array $json ): array|\WP_Error {
		return self::decode(
			wp_remote_post(
				$url,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => $headers + array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( $json ),
				)
			)
		);
	}

	/** @return array{status:int,body:array<mixed>}|\WP_Error */
	private static function decode( mixed $response ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'provider_unavailable', 'El proveedor de mapas no respondió.' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status ) {
			return self::httpError( $status, $body );
		}
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'provider_unavailable', 'El proveedor de mapas devolvió una respuesta que no es JSON.', array( 'status' => $status ) );
		}
		// Algunos errores llegan con HTTP 200 y un objeto `error` dentro (o en una lista, ver `errorNode`).
		$error = self::errorNode( $body );
		if ( null !== $error || isset( $body['error'] ) ) {
			$code = null !== $error ? (int) ( $error['code'] ?? $status ) : $status;
			return self::httpError( $code, $body );
		}

		return array(
			'status' => $status,
			'body'   => $body,
		);
	}

	/**
	 * Código estable para un error del proveedor. El estado, el estado textual de Google y el
	 * motivo estructurado (`error.details[].reason`) mandan sobre el resto.
	 *
	 * Un `API_KEY_INVALID` llega con HTTP 400 pero es una clave rechazada; tratarlo como caída del
	 * servicio haría que la prueba de conexión diera una pista equivocada.
	 */
	public static function httpError( int $status, mixed $body ): \WP_Error {
		$error  = self::errorNode( $body ) ?? array();
		$text   = (string) ( $error['status'] ?? '' );
		$reason = self::reason( $error );
		$data   = array(
			'status' => $status,
			'reason' => $reason,
		);

		if ( 'API_KEY_INVALID' === $reason || in_array( $status, array( 401, 403 ), true ) || in_array( $text, array( 'PERMISSION_DENIED', 'UNAUTHENTICATED' ), true ) ) {
			return new \WP_Error( 'provider_unauthorized', 'El proveedor de mapas rechazó la clave o el servicio no está habilitado.', $data );
		}
		if ( 429 === $status || 'RESOURCE_EXHAUSTED' === $text ) {
			return new \WP_Error( 'provider_rate_limited', 'El proveedor de mapas alcanzó su límite de uso.', $data );
		}

		return new \WP_Error( 'provider_unavailable', 'El proveedor de mapas no está disponible en este momento.', $data );
	}

	/**
	 * Nodo `error` del cuerpo: en la raíz (Geocoding, ORS) o, en Routes API (`computeRouteMatrix`, que
	 * responde con una lista), dentro del primer elemento: `[{ "error": { … } }]`.
	 *
	 * @return array<mixed>|null
	 */
	private static function errorNode( mixed $body ): ?array {
		if ( ! is_array( $body ) ) {
			return null;
		}
		if ( is_array( $body['error'] ?? null ) ) {
			return $body['error'];
		}
		if ( array_is_list( $body ) && is_array( $body[0] ?? null ) && is_array( $body[0]['error'] ?? null ) ) {
			return $body[0]['error'];
		}
		return null;
	}

	/**
	 * Motivo corto del error estructurado del proveedor (`error.details[]`). Solo letras, números y
	 * guion bajo: nunca se reenvía el mensaje libre, que puede traer datos de la petición.
	 *
	 * @param array<mixed> $error Nodo `error` del cuerpo del proveedor.
	 */
	private static function reason( array $error ): string {
		$details = $error['details'] ?? null;
		if ( ! is_array( $details ) ) {
			return '';
		}
		foreach ( $details as $detail ) {
			$value = is_array( $detail ) ? ( $detail['reason'] ?? null ) : null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$clean = preg_replace( '/[^A-Za-z0-9_]/', '', trim( $value ) );
				return strtoupper( is_string( $clean ) ? $clean : '' );
			}
		}
		return '';
	}
}
