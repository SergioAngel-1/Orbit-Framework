<?php

namespace HWE\Shipping\Admin;

use HWE\Shipping\PostTypes;
use HWE\Shipping\Save;

/**
 * Aviso de admin con el motivo por el que una ciudad no se ha publicado. El motivo queda en el post
 * hasta que la ciudad se guarda bien.
 */
class CityErrors {

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function render(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== PostTypes::RATE_SLUG || $screen->base !== 'post' ) {
			return;
		}
		$postId = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $postId <= 0 ) {
			return;
		}

		$errors = json_decode( (string) get_post_meta( $postId, Save::ERRORS_META, true ), true );
		if ( ! is_array( $errors ) || $errors === [] ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Esta ciudad no se ha publicado. Corrige lo siguiente y vuelve a guardar:', 'hwe-shipping' ) . '</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( (string) $error ) . '</li>';
		}
		echo '</ul></div>';
	}
}
