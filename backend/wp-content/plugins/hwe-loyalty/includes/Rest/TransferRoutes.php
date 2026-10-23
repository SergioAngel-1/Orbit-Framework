<?php
/**
 * Ruta interna de transferencias (`POST hwe/v1/loyalty/transfer`), solo para el BFF.
 *
 * Se registra en un archivo propio (y no en `ReferralRoutes`) porque su puerta es distinta:
 * exige que las **transferencias** estén activas (`transfersActive()`), no que lo estén los
 * referidos. Comparten namespace, el secreto interno y la respuesta 404 cuando la función está
 * apagada —así el navegador no puede distinguir una ruta que no existe de una apagada—, pero
 * mantenerlas separadas evita que apagar un interruptor cierre la ruta del otro.
 *
 * El `user_id` lo decide el BFF a partir de la sesión verificada, nunca el navegador; la ruta
 * confía en el secreto interno y no autentica al usuario final. La respuesta de error es solo
 * `{ error: <código> }`; ni el email ni el login del receptor salen nunca de aquí.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_TransferRoutes {

	public const NAMESPACE = 'hwe/v1';

	/** Engancha el registro de la ruta. La llama `HWE_Loyalty::boot()`. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/loyalty/transfer',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'permission' ),
				'callback'            => array( self::class, 'handleTransfer' ),
			)
		);
	}

	/**
	 * Puerta común: secreto interno del BFF y transferencias encendidas.
	 *
	 * Sin el secreto devuelve `false` (WordPress responde 401/403); con el módulo, los puntos o
	 * el interruptor apagados devuelve el mismo `WP_Error` que una ruta inexistente (404),
	 * para no revelar que existe.
	 *
	 * @return bool|\WP_Error
	 */
	public static function permission( $request ) {
		if ( ! function_exists( 'hwe_has_internal_secret' ) || ! hwe_has_internal_secret( $request ) ) {
			return false;
		}

		if ( ! HWE_Loyalty_Settings::transfersActive() ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/** 200 con el resultado, o 4xx/5xx con `{ error }`. */
	public static function handleTransfer( $request ): WP_REST_Response {
		$userId = (int) $request->get_param( 'user_id' );
		$code   = (string) $request->get_param( 'code' );
		$points = (int) $request->get_param( 'points' );
		$key    = (string) $request->get_param( 'idempotency_key' );
		$note   = (string) ( $request->get_param( 'note' ) ?? '' );

		if ( $userId <= 0 || '' === trim( $code ) || $points <= 0 || '' === trim( $key ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_request' ), 400 );
		}

		$result = HWE_Loyalty_Transfers::transfer( $userId, $code, $points, $key, $note );
		if ( ! empty( $result['ok'] ) ) {
			return new WP_REST_Response( $result, 200 );
		}

		$error = isset( $result['error'] ) && is_string( $result['error'] ) ? $result['error'] : 'failed';

		return new WP_REST_Response( array( 'error' => $error ), self::statusFor( $error ) );
	}

	/** Código estable → estado HTTP. Los motivos de negocio son 422 (no se puede procesar). */
	private static function statusFor( string $error ): int {
		switch ( $error ) {
			case 'invalid_request':
				return 400;
			case 'disabled':
				return 403;
			case 'conflict':
				return 409;
			case 'failed':
				return 500;
			default:
				return 422;
		}
	}
}
