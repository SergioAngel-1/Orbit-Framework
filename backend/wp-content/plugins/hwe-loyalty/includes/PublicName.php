<?php
/**
 * Nombre de un usuario tal como lo ve OTRO usuario: la red de referidos, el recibo de una
 * transferencia y el texto de sus movimientos. La administración (wp-admin) no lo usa: el
 * equipo de la tienda sí puede ver logins.
 *
 * El registro del BFF crea la cuenta con `registerUser(username, email, password)` y WordPress
 * pone el login como `display_name`, así que devolverlo tal cual enseñaba el login de un
 * cliente a otro. Regla: el `display_name` solo vale si no es el login ni un email; si no, el
 * nombre de pila con la inicial del apellido; si tampoco hay, cadena vacía (el frontend ya
 * trata el nombre vacío como «sin nombre», igual que el de una cuenta eliminada).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_PublicName {

	public static function of( int $userId ): string {
		if ( $userId <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}

		$user = get_userdata( $userId );
		if ( ! is_object( $user ) ) {
			return '';
		}

		$login   = self::field( $user, 'user_login' );
		$display = self::field( $user, 'display_name' );
		if ( '' !== $display && 0 !== strcasecmp( $display, $login ) && ! str_contains( $display, '@' ) ) {
			return $display;
		}

		$first = self::field( $user, 'first_name' );
		$last  = self::field( $user, 'last_name' );
		if ( '' === $first || str_contains( $first, '@' ) || 0 === strcasecmp( $first, $login ) ) {
			return '';
		}

		return '' !== $last ? $first . ' ' . mb_substr( $last, 0, 1 ) . '.' : $first;
	}

	private static function field( object $user, string $name ): string {
		$value = $user->{$name} ?? '';
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		return trim( function_exists( 'sanitize_text_field' ) ? (string) sanitize_text_field( $value ) : $value );
	}
}
