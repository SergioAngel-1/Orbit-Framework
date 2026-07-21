<?php

namespace HWE\Banners;

/**
 * Registro de posiciones (placements) donde una instancia puede colocar banners.
 * Genérico: el frontend decide cómo renderiza cada placement. Filtrable para que
 * una instancia añada/renombre posiciones sin tocar el plugin.
 */
class Placements {

	/** @return array<string,string> slug => etiqueta legible */
	public static function all(): array {
		$defaults = [
			'hero'   => __( 'Hero (portada)', 'hwe-banners' ),
			'middle' => __( 'Intermedio', 'hwe-banners' ),
			'bottom' => __( 'Inferior', 'hwe-banners' ),
		];
		$placements = apply_filters( 'hwe_banners_placements', $defaults );

		$clean = [];
		foreach ( (array) $placements as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug !== '' ) {
				$clean[ $slug ] = (string) $label;
			}
		}
		return $clean !== [] ? $clean : $defaults;
	}

	public static function isValid( string $slug ): bool {
		return array_key_exists( $slug, self::all() );
	}

	public static function defaultSlug(): string {
		return (string) array_key_first( self::all() );
	}
}
