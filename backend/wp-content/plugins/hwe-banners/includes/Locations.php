<?php

namespace HWE\Banners;

/**
 * Catálogo de locaciones (páginas/superficies) donde puede mostrarse un banner.
 * Genérico: la instancia añade las suyas vía `hwe_banners_locations`.
 *
 * Forma del filtro: slug => 'Etiqueta' | [ 'label' => … ]
 */
class Locations {

	/**
	 * @return array<string,string> slug => label
	 */
	public static function all(): array {
		$defaults = [
			'home' => __( 'Inicio', 'hwe-banners' ),
		];
		$locations = apply_filters( 'hwe_banners_locations', $defaults );

		$clean = [];
		foreach ( (array) $locations as $slug => $cfg ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' ) {
				continue;
			}
			$clean[ $slug ] = is_array( $cfg )
				? (string) ( $cfg['label'] ?? $slug )
				: (string) $cfg;
		}
		if ( $clean === [] ) {
			$clean = $defaults;
		}
		return $clean;
	}

	public static function isValid( string $slug ): bool {
		return array_key_exists( $slug, self::all() );
	}

	public static function label( string $slug ): string {
		$all = self::all();
		return $all[ $slug ] ?? $slug;
	}

	public static function defaultSlug(): string {
		return (string) array_key_first( self::all() );
	}
}
