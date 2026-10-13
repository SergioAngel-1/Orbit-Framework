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
			AdminViews::register();
			Assets::register();
			HelpPage::register();
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
	 * Locales secundarios para los que se ofrecen overrides por idioma: los idiomas secundarios
	 * del sitio (`i18n.locales` del Control Center, vía `hwe-content-i18n`), normalizados como
	 * sufijo de clave (`pt-BR` → `pt_br`). Con un solo idioma, ninguno.
	 *
	 * Sustituye al filtro `hwe_banners_secondary_locales` de la versión anterior (un único idioma
	 * secundario, `en`): ahora el sitio declara los idiomas en un solo sitio.
	 *
	 * @return array<int,string>
	 */
	public static function secondaryLocales(): array {
		if ( ! function_exists( 'hwe_i18n_locales' ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', hwe_i18n_locales() ) ) ) );
	}
}
