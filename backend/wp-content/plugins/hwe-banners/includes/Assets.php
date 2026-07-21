<?php

namespace HWE\Banners;

/**
 * Enqueue de CSS/JS del editor de banners. Solo en pantallas de edición del CPT.
 */
class Assets {

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== PostType::SLUG ) {
			return;
		}
		$ver = defined( 'HWE_BANNERS_VERSION' ) ? HWE_BANNERS_VERSION : '1.0.0';
		wp_enqueue_style( 'hwe-banners-admin', HWE_BANNERS_URL . 'assets/admin.css', [], $ver );
		wp_enqueue_script( 'hwe-banners-admin', HWE_BANNERS_URL . 'assets/admin.js', [ 'jquery' ], $ver, true );
	}
}
