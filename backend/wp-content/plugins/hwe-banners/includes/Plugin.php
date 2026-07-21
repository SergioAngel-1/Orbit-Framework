<?php

namespace HWE\Banners;

/**
 * Punto de arranque del plugin. Engancha los subsistemas en el orden correcto.
 */
class Plugin {

	public static function boot(): void {
		add_action( 'init', [ PostType::class, 'register' ] );
		if ( is_admin() ) {
			MetaBoxes::register();
			AdminColumns::register();
			Assets::register();
		}
		Save::register();
		RestApi::register();
		Revalidation::register();
	}

	/** Locale base del framework (canónico, sin prefijo). */
	public static function defaultLocale(): string {
		return 'es';
	}

	/**
	 * Locales secundarios para los que se ofrecen overrides por idioma.
	 * Filtrable para que una instancia con más idiomas los declare.
	 *
	 * @return array<int,string>
	 */
	public static function secondaryLocales(): array {
		$locales = apply_filters( 'hwe_banners_secondary_locales', [ 'en' ] );
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $locales ) ) ) );
	}
}
