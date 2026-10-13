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

		wp_enqueue_style(
			'hwe-banners-admin',
			HWE_BANNERS_URL . 'assets/admin.css',
			[],
			self::assetVersion( 'assets/admin.css' )
		);
		wp_enqueue_script(
			'hwe-banners-admin',
			HWE_BANNERS_URL . 'assets/admin.js',
			[ 'jquery' ],
			self::assetVersion( 'assets/admin.js' ),
			true
		);

		// Config de tipos (label/description/fields) para que el JS del creador de
		// slides oculte campos y muestre la descripción según el tipo elegido.
		wp_localize_script( 'hwe-banners-admin', 'HWEBannersConfig', [
			'types' => SlideTypes::all(),
		] );
	}

	/**
	 * Versión de un asset para cache-busting. Combina la versión del plugin con
	 * el `filemtime` del archivo, de modo que cualquier edición del CSS/JS invalida
	 * la caché del navegador automáticamente (sin tener que subir HWE_BANNERS_VERSION
	 * a mano). Si el archivo no se puede leer, cae a la versión del plugin.
	 */
	private static function assetVersion( string $relPath ): string {
		$base = defined( 'HWE_BANNERS_VERSION' ) ? HWE_BANNERS_VERSION : '1.0.0';
		$file = HWE_BANNERS_DIR . '/' . ltrim( $relPath, '/' );
		if ( is_readable( $file ) ) {
			$mtime = filemtime( $file );
			if ( $mtime ) {
				return $base . '.' . $mtime;
			}
		}
		return $base;
	}
}
