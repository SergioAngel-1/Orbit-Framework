<?php

namespace HWE\Banners;

/**
 * Tipos de banner (rol): carrusel, franja, aliados, etc. Cada tipo declara
 * plantillas de slide permitidas, máximo de slides, intervalo de carrusel y
 * locaciones admitidas. La instancia personaliza vía `hwe_banners_banner_types`.
 *
 * Formas en el filtro:
 *   - string: slug => 'Etiqueta'
 *   - array:  slug => [ 'label', 'description', 'types', 'max_slides',
 *                       'interval', 'locations' ]
 */
class BannerTypes {

	/**
	 * @return array<string,array{label:string,description:string,types:array<int,string>|null,max_slides:int|null,interval:bool,locations:array<int,string>}>
	 */
	public static function all(): array {
		$defaults = [
			'carousel' => [
				'label'       => __( 'Carrusel', 'hwe-banners' ),
				'description' => __( 'Carrusel de slides con plantillas. Requiere locación.', 'hwe-banners' ),
				'interval'    => true,
				'locations'   => [ 'home' ],
			],
			'band'     => [
				'label'       => __( 'Intermedio (franja)', 'hwe-banners' ),
				'description' => __( 'Franja única (1 slide).', 'hwe-banners' ),
				'max_slides'  => 1,
				'interval'    => false,
				'locations'   => [ 'home' ],
			],
			'allies'    => [
				'label'       => __( 'Aliados', 'hwe-banners' ),
				'description' => __( 'Tira de logos/aliados.', 'hwe-banners' ),
				'interval'    => false,
				'locations'   => [ 'home' ],
			],
			'page_hero' => [
				'label'       => __( 'Hero de página', 'hwe-banners' ),
				'description' => __( 'Imagen del hero en páginas internas (no home). Solo foto; textos en el frontend.', 'hwe-banners' ),
				'max_slides'  => 1,
				'interval'    => false,
				'locations'   => [ 'store' ],
			],
			'landing'   => [
				'label'       => __( 'Banner Landing', 'hwe-banners' ),
				'description' => __( 'Hero a sangrado con panel de copy opcional (landings).', 'hwe-banners' ),
				'max_slides'  => 1,
				'interval'    => false,
				'locations'   => [ 'home' ],
			],
		];
		$types = apply_filters( 'hwe_banners_banner_types', $defaults );

		$clean = [];
		foreach ( (array) $types as $slug => $cfg ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug !== '' ) {
				$clean[ $slug ] = self::normalize( $slug, $cfg );
			}
		}
		if ( $clean === [] ) {
			foreach ( $defaults as $slug => $cfg ) {
				$clean[ $slug ] = self::normalize( $slug, $cfg );
			}
		}
		return $clean;
	}

	/**
	 * @param string|array<string,mixed> $cfg
	 * @return array{label:string,description:string,types:array<int,string>|null,max_slides:int|null,interval:bool,locations:array<int,string>}
	 */
	private static function normalize( string $slug, $cfg ): array {
		$fallbackLoc = [ Locations::defaultSlug() ];
		if ( is_array( $cfg ) ) {
			$types = ( isset( $cfg['types'] ) && is_array( $cfg['types'] ) )
				? array_values( array_filter( array_map( 'sanitize_key', $cfg['types'] ) ) )
				: null;
			$locations = ( isset( $cfg['locations'] ) && is_array( $cfg['locations'] ) )
				? array_values( array_filter( array_map( 'sanitize_key', $cfg['locations'] ) ) )
				: $fallbackLoc;
			if ( $locations === [] ) {
				$locations = $fallbackLoc;
			}
			return [
				'label'       => (string) ( $cfg['label'] ?? $slug ),
				'description' => (string) ( $cfg['description'] ?? '' ),
				'types'       => $types !== [] && $types !== null ? $types : null,
				'max_slides'  => ( isset( $cfg['max_slides'] ) && (int) $cfg['max_slides'] > 0 ) ? (int) $cfg['max_slides'] : null,
				'interval'    => ! isset( $cfg['interval'] ) || (bool) $cfg['interval'],
				'locations'   => $locations,
			];
		}
		return [
			'label'       => (string) $cfg,
			'description' => '',
			'types'       => null,
			'max_slides'  => null,
			'interval'    => true,
			'locations'   => $fallbackLoc,
		];
	}

	/** @return array{label:string,description:string,types:array<int,string>|null,max_slides:int|null,interval:bool,locations:array<int,string>}|null */
	public static function config( string $slug ): ?array {
		return self::all()[ $slug ] ?? null;
	}

	/** @return array<string,string> */
	public static function labels(): array {
		return array_map( static fn( array $cfg ): string => $cfg['label'], self::all() );
	}

	public static function isValid( string $slug ): bool {
		return array_key_exists( $slug, self::all() );
	}

	public static function defaultSlug(): string {
		return (string) array_key_first( self::all() );
	}

	public static function isLocationAllowed( string $type, string $location ): bool {
		$cfg = self::config( $type );
		if ( $cfg === null ) {
			return false;
		}
		return in_array( $location, $cfg['locations'], true ) && Locations::isValid( $location );
	}

	/** Primera locación permitida para el tipo. */
	public static function defaultLocationFor( string $type ): string {
		$cfg = self::config( $type );
		if ( $cfg === null || $cfg['locations'] === [] ) {
			return Locations::defaultSlug();
		}
		return $cfg['locations'][0];
	}

	/**
	 * @return array<string,array{label:string,description:string,fields:array<int,string>|null}>
	 */
	public static function allowedSlideTypes( string $type ): array {
		$all     = SlideTypes::all();
		$cfg     = self::config( $type );
		$allowed = $cfg['types'] ?? null;
		if ( $allowed === null ) {
			return $all;
		}
		$out = [];
		foreach ( $allowed as $slideType ) {
			if ( isset( $all[ $slideType ] ) ) {
				$out[ $slideType ] = $all[ $slideType ];
			}
		}
		return $out !== [] ? $out : $all;
	}

	public static function defaultSlideTypeFor( string $type ): string {
		$allowed = self::allowedSlideTypes( $type );
		return isset( $allowed['default'] ) ? 'default' : (string) array_key_first( $allowed );
	}

	public static function isSlideTypeAllowed( string $bannerType, string $slideType ): bool {
		return array_key_exists( $slideType, self::allowedSlideTypes( $bannerType ) );
	}
}
