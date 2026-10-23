<?php
/**
 * Resolución y presentación de usuarios en wp-admin, sin datos personales de más.
 *
 * El equipo busca por id, email o login porque son las tres formas que ya usa el listado de
 * WordPress; la salida solo muestra el nombre visible (o `#id` si la cuenta desapareció).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HWE_Loyalty_Admin_Users {

	/**
	 * Resuelve el término de búsqueda a un id: id numérico, email o login. `null` si no existe.
	 * No busca por nombre visible: dos personas pueden llamarse igual y el id sería ambiguo.
	 */
	public static function resolve( string $term ): ?int {
		$term = trim( $term );
		if ( '' === $term ) {
			return null;
		}

		if ( ctype_digit( $term ) ) {
			$user = get_userdata( (int) $term );
			return is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : null;
		}

		if ( ! function_exists( 'get_user_by' ) ) {
			return null;
		}

		foreach ( array( 'email', 'login', 'slug' ) as $field ) {
			$user = get_user_by( $field, $term );
			if ( is_object( $user ) && isset( $user->ID ) ) {
				return (int) $user->ID;
			}
		}

		return null;
	}

	/** Nombre visible, escapado por quien lo pinta. `#id` para una cuenta eliminada. */
	public static function label( int $userId ): string {
		if ( $userId <= 0 ) {
			return '—';
		}

		$user = function_exists( 'get_userdata' ) ? get_userdata( $userId ) : false;
		if ( ! is_object( $user ) ) {
			return '#' . $userId;
		}

		$name = isset( $user->display_name ) ? trim( (string) $user->display_name ) : '';
		return '' !== $name ? $name : '#' . $userId;
	}

	/** URL del perfil de WordPress (donde está la sección del módulo). */
	public static function profileUrl( int $userId ): string {
		return admin_url( 'user-edit.php?user_id=' . $userId );
	}
}
