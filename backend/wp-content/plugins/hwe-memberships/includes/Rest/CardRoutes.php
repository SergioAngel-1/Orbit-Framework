<?php
/**
 * Rutas internas del carné digital (`hwe/v1/memberships/card` y `…/verify`).
 *
 * Solo las consume el BFF, con `X-HWE-Internal-Secret`; `hwe-bff-only` cierra todo
 * `/hwe/v1/memberships/` al navegador. Dos lecturas:
 *  · `GET memberships/card?user_id`   → emite el carné del usuario y devuelve `token`,
 *                                       `expires_at` (ISO 8601 UTC) y `verify_path` (la ruta
 *                                       del frontend; la URL absoluta la compone el BFF con
 *                                       `config.brand.url`). Sin nivel efectivo: 403 tipado
 *                                       `membership_card_unavailable`.
 *  · `GET memberships/verify?token`   → verificación pública. Responde los datos mínimos de
 *                                       §9 o `{ valid: false }`. Un token inexistente,
 *                                       caducado o revocado da exactamente la misma respuesta
 *                                       (mismo código y mismas claves), sin distinguir el
 *                                       motivo ni revelar la causa.
 *
 * `user_id` es obligatorio y entero > 0 (400 si no). Con el módulo o el carné apagados, la
 * puerta responde el mismo 404 que una ruta inexistente. El rate-limit por IP de la
 * verificación pública vive en el BFF (Tarea 18), no aquí.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Memberships_CardRoutes {

	public const NAMESPACE = 'hwe/v1';

	/** Engancha el registro de rutas. La llama `HWE_Memberships::boot()`. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/memberships/card',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'permission' ),
				'callback'            => array( self::class, 'handleCard' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/memberships/verify',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'permission' ),
				'callback'            => array( self::class, 'handleVerify' ),
			)
		);
	}

	/**
	 * Secreto interno + carné activo. Sin el secreto devuelve `false`; con el módulo o el
	 * interruptor apagados, el mismo `WP_Error` que una ruta inexistente (404), para no
	 * revelar que existe.
	 *
	 * @param mixed $request
	 * @return bool|\WP_Error
	 */
	public static function permission( $request ) {
		if ( ! function_exists( 'hwe_has_internal_secret' ) || ! hwe_has_internal_secret( $request ) ) {
			return false;
		}

		if ( ! HWE_Memberships_Settings::cardActive() ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/** Emite el carné del usuario. */
	public static function handleCard( $request ): WP_REST_Response {
		$userId = self::userId( $request );
		if ( $userId <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}

		$issued = HWE_Memberships_Card::issue( $userId );
		if ( null === $issued ) {
			return new WP_REST_Response( array( 'error' => 'membership_card_unavailable' ), 403 );
		}

		$settings = HWE_Memberships_Settings::get();

		return new WP_REST_Response(
			array(
				'token'       => (string) ( $issued['token'] ?? '' ),
				'expires_at'  => (string) ( $issued['expires_at'] ?? '' ),
				'verify_path' => (string) ( $settings['card_verify_path'] ?? '' ),
			),
			200
		);
	}

	/** Verificación pública del carné: datos mínimos o `{ valid: false }`. */
	public static function handleVerify( $request ): WP_REST_Response {
		$raw = $request->get_param( 'token' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}

		$data = HWE_Memberships_Card::verify( trim( $raw ) );

		return new WP_REST_Response( null === $data ? array( 'valid' => false ) : $data, 200 );
	}

	/** `user_id` obligatorio y > 0; 0 si falta o no es numérico. */
	private static function userId( $request ): int {
		$raw = $request->get_param( 'user_id' );

		return is_scalar( $raw ) && is_numeric( $raw ) ? (int) $raw : 0;
	}
}
