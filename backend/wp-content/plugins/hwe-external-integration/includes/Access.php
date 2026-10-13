<?php

namespace HWE\ExternalIntegration;

/**
 * Cómo se autentica el usuario de servicio y qué peticiones cuentan como «de la integración».
 *
 * La credencial es una Application Password, NO una clave de la API REST de WooCommerce
 * (`ck_…`/`cs_…`): una clave REST abre `wc/v3` entero con los permisos de quien la crea, sin
 * restricción de IP ni lista de operaciones.
 */
final class Access {

	public static function register(): void {
		// Solo el usuario de servicio puede tener Application Passwords.
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'availableForUser' ), 10, 2 );
		// Candados de la contraseña (solo se evalúan cuando ya es CORRECTA).
		add_action( 'wp_authenticate_application_password_errors', array( self::class, 'locks' ), 10, 2 );
		// Sin inicio de sesión interactivo.
		add_filter( 'wp_authenticate_user', array( self::class, 'noInteractiveLogin' ), 1 );
	}

	/**
	 * @param mixed $available
	 * @param mixed $user
	 */
	public static function availableForUser( $available, $user ): bool {
		return (bool) $available && Role::userHasRole( $user );
	}

	/**
	 * La contraseña de aplicación es correcta; añadir un error la invalida:
	 *   · solo desde una IP autorizada;
	 *   · solo en peticiones GraphQL (no sirve en REST: `wc/v3` queda fuera de su alcance).
	 * Ante cualquier duda (función ausente) falla cerrado.
	 *
	 * @param mixed $error
	 * @param mixed $user
	 */
	public static function locks( $error, $user ): void {
		if ( ! $error instanceof \WP_Error || ! Role::userHasRole( $user ) ) {
			return;
		}
		if ( ! function_exists( 'hwe_bff_only_is_integration_call' ) || ! hwe_bff_only_is_integration_call() ) {
			$error->add( 'hwe_integration_ip', 'Origen no autorizado.' );
			return;
		}
		if ( ! function_exists( 'is_graphql_http_request' ) || ! is_graphql_http_request() ) {
			$error->add( 'hwe_integration_graphql_only', 'Esta credencial solo sirve para GraphQL.' );
		}
	}

	/**
	 * @param mixed $user
	 * @return mixed
	 */
	public static function noInteractiveLogin( $user ) {
		if ( Role::userHasRole( $user ) ) {
			return new \WP_Error( 'hwe_integration_no_login', 'Esta cuenta es solo para la integración.' );
		}
		return $user;
	}

	/**
	 * ¿Esta petición es del integrador externo? Por IP (aunque no se haya autenticado todavía) o
	 * por rol (si alguien consiguiera su credencial desde otro lado). Las restricciones de
	 * `GraphqlGuard` cuelgan de aquí. Una llamada del BFF (secreto interno) o de WP-CLI no
	 * cuenta nunca.
	 */
	public static function isRestrictedRequest(): bool {
		if ( function_exists( 'hwe_bff_only_is_trusted_call' ) && hwe_bff_only_is_trusted_call() ) {
			return false;
		}
		if ( function_exists( 'hwe_bff_only_is_integration_call' ) && hwe_bff_only_is_integration_call() ) {
			return true;
		}
		return Role::userHasRole( wp_get_current_user() );
	}
}
