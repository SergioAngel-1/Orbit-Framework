<?php

namespace HWE\Shipping\Admin;

use HWE\Shipping\Rates\Cities;

/**
 * Aviso global de wp-admin: hay ciudades del modelo anterior (sin tipo de tarifa) que no tienen
 * envío hasta que se reconfiguren. Enlaza a cada una.
 */
class LegacyNotice {

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$ids = Cities::legacyPostIds();
		if ( $ids === [] ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Estas ciudades usan el modelo de tarifa anterior y no tienen envío hasta que las reconfigures (tipo fijo o por distancia):', 'hwe-shipping' ) . '</p><ul>';
		foreach ( $ids as $id ) {
			echo '<li><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( (string) get_the_title( $id ) ) . '</a></li>';
		}
		echo '</ul></div>';
	}
}
