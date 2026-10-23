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
 * Los errores llevan el estado HTTP en `data.status` para que el proveedor pueda decidir (p. ej.
 * un 404 de ruta en OpenRouteService).
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
		// Algunos errores llegan con HTTP 200 y un objeto `error` dentro.
		if ( isset( $body['error'] ) ) {
			$code = is_array( $body['error'] ) ? (int) ( $body['error']['code'] ?? $status ) : $status;
			return self::httpError( $code, $body );
		}

		return array(
			'status' => $status,
			'body'   => $body,
		);
	}

	/** Código estable para un error del proveedor. El estado y el estado textual de Google mandan. */
	public static function httpError( int $status, mixed $body ): \WP_Error {
		$text = is_array( $body ) && is_array( $body['error'] ?? null ) ? (string) ( $body['error']['status'] ?? '' ) : '';

		if ( in_array( $status, array( 401, 403 ), true ) || in_array( $text, array( 'PERMISSION_DENIED', 'UNAUTHENTICATED' ), true ) ) {
			return new \WP_Error( 'provider_unauthorized', 'El proveedor de mapas rechazó la clave o el servicio no está habilitado.', array( 'status' => $status ) );
		}
		if ( 429 === $status || 'RESOURCE_EXHAUSTED' === $text ) {
			return new \WP_Error( 'provider_rate_limited', 'El proveedor de mapas alcanzó su límite de uso.', array( 'status' => $status ) );
		}

		return new \WP_Error( 'provider_unavailable', 'El proveedor de mapas no está disponible en este momento.', array( 'status' => $status ) );
	}
}
