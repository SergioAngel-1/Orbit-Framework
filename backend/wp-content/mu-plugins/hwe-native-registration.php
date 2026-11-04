<?php
/**
 * Plugin Name: HWE Native Registration Off
 * Description: Cierra el registro nativo de WordPress (enlace «Registrarse» y
 *              `?action=register` del login) sin tocar el registro headless por GraphQL.
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 *
 * `setup.sh` activa `users_can_register` porque la mutación `registerUser` de WPGraphQL
 * —la que usa el BFF en `/api/auth/register`— lo exige. Esa opción también abre el
 * formulario de registro de wp-login.php, que crea cuentas saltándose el BFF (sus
 * validaciones, su rate-limit y el consentimiento). La opción se deja como está y se
 * cierran las vías nativas:
 *
 *   - El enlace «Registrarse» del login, de recuperar contraseña y de `wp_register()`
 *     (filtro `register`).
 *   - `?action=register` del login (formulario y envío): vuelve al login con
 *     `registration=disabled`, el aviso nativo de «registro no permitido». Con
 *     `hwe-login-slug` la URL sale ya por el slug.
 *   - Cualquier otra llamada a `register_new_user()` fuera de una petición GraphQL
 *     (filtro `registration_errors`), por si un plugin la expone.
 *
 * Una instancia que quiera el registro nativo lo reabre con
 * `add_filter( 'hwe_native_registration', '__return_true' );` desde `mu-plugins/instance/`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** ¿Se admite el registro nativo? Filtro `hwe_native_registration`, cerrado por defecto. */
function hwe_native_registration_allowed(): bool {
	return (bool) apply_filters( 'hwe_native_registration', false );
}

/** ¿La petición en curso ejecuta GraphQL (la vía del BFF)? */
function hwe_native_registration_is_graphql(): bool {
	return function_exists( 'is_graphql_request' ) && is_graphql_request();
}

/**
 * Quita el enlace «Registrarse».
 *
 * @param mixed $link HTML del enlace.
 * @return mixed
 */
function hwe_native_registration_link( $link ) {
	return hwe_native_registration_allowed() ? $link : '';
}
add_filter( 'register', 'hwe_native_registration_link' );

/** Login con el aviso nativo de registro cerrado. */
function hwe_native_registration_disabled_url(): string {
	return add_query_arg( 'registration', 'disabled', wp_login_url() );
}

/** `?action=register` (GET del formulario y POST del envío) vuelve al login. */
function hwe_native_registration_block_form(): void {
	if ( hwe_native_registration_allowed() ) {
		return;
	}
	wp_safe_redirect( hwe_native_registration_disabled_url() );
	exit;
}
add_action( 'login_form_register', 'hwe_native_registration_block_form' );

/**
 * `register_new_user()` solo prospera dentro de una petición GraphQL.
 *
 * @param WP_Error $errors Errores del registro.
 * @return WP_Error
 */
function hwe_native_registration_errors( $errors ) {
	if ( hwe_native_registration_allowed() || hwe_native_registration_is_graphql() || ! is_wp_error( $errors ) ) {
		return $errors;
	}
	$errors->add( 'hwe_registration_disabled', __( 'El registro de usuarios no está permitido.', 'hwe' ) );
	return $errors;
}
add_filter( 'registration_errors', 'hwe_native_registration_errors' );
