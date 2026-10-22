<?php
/**
 * Rutas internas de referidos (`hwe/v1/loyalty/…`), solo para el BFF.
 *
 * Tres operaciones puntuales que no necesitan el carrito:
 *  · `POST loyalty/attribute`   → atribuye una cuenta recién creada a un código.
 *  · `GET  loyalty/validate-code` → `{ valid }` y nada más (puede comprobarlo el registro).
 *  · `GET  loyalty/referral-code` → código de un usuario (lo pide su enlace de referido).
 *
 * Todas exigen `X-HWE-Internal-Secret` y, además, que «Referidos» esté encendido: con el
 * módulo o el interruptor apagados responden 404 como una ruta inexistente. La puerta se
 * refuerza en `hwe-bff-only` (defensa en profundidad, spec §9.2).
 *
 * El `user_id` lo decide el BFF a partir de la sesión verificada, nunca el navegador; estas
 * rutas confían en el secreto interno y no autentican al usuario final.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_ReferralRoutes {

	public const NAMESPACE = 'hwe/v1';

	/** Engancha el registro de rutas. La llama `HWE_Loyalty::boot()`. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	public static function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/loyalty/attribute',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'permission' ),
				'callback'            => array( self::class, 'handleAttribute' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/loyalty/validate-code',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'permission' ),
				'callback'            => array( self::class, 'handleValidateCode' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/loyalty/referral-code',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( self::class, 'permission' ),
				'callback'            => array( self::class, 'handleReferralCode' ),
			)
		);
	}

	/**
	 * Puerta común: secreto interno del BFF y «Referidos» encendido.
	 *
	 * Sin el secreto devuelve `false` (WordPress responde 401/403); con el módulo o el
	 * interruptor apagados devuelve el mismo `WP_Error` que una ruta inexistente (404), para
	 * no revelar que existe.
	 *
	 * @return bool|\WP_Error
	 */
	public static function permission( $request ) {
		if ( ! function_exists( 'hwe_has_internal_secret' ) || ! hwe_has_internal_secret( $request ) ) {
			return false;
		}

		if ( ! HWE_Loyalty_Settings::referralsActive() ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/** `{ result: 'attributed' | 'ignored:<motivo>' }`. */
	public static function handleAttribute( $request ): WP_REST_Response {
		$userId = (int) $request->get_param( 'user_id' );
		$code   = (string) $request->get_param( 'code' );

		if ( $userId <= 0 || '' === trim( $code ) ) {
			return new WP_REST_Response( array( 'result' => 'ignored:invalid_code' ), 400 );
		}

		return new WP_REST_Response(
			array( 'result' => HWE_Loyalty_Referrals::attribute( $userId, $code ) ),
			200
		);
	}

	/** `{ valid: bool }` y nada más: no expone el dueño ni los datos de la cuenta. */
	public static function handleValidateCode( $request ): WP_REST_Response {
		$code = (string) $request->get_param( 'code' );

		if ( '' === trim( $code ) ) {
			return new WP_REST_Response( array( 'valid' => false ), 400 );
		}

		$userId = HWE_Loyalty_Referrals::userByCode( $code );
		$valid  = null !== $userId && HWE_Loyalty_Settings::canParticipate( $userId );

		return new WP_REST_Response( array( 'valid' => $valid ), 200 );
	}

	/** `{ code: string }`; vacío si el usuario no existe o no participa. */
	public static function handleReferralCode( $request ): WP_REST_Response {
		$userId = (int) $request->get_param( 'user_id' );

		if ( $userId <= 0 ) {
			return new WP_REST_Response( array( 'code' => '' ), 400 );
		}

		return new WP_REST_Response(
			array( 'code' => HWE_Loyalty_Referrals::codeFor( $userId ) ),
			200
		);
	}
}
