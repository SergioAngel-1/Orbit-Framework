<?php

namespace HWE\Banners;

/**
 * Serializa banners publicados. Una celda (type + location) = un solo post
 * (el más recientemente modificado). Resuelve overrides i18n.
 */
class Serializer {

	private const CACHE_GROUP = 'hwe_banners';
	private const CACHE_TTL   = 300; // 5 min — invalidado también al guardar/borrar un banner (ver Revalidation).

	/**
	 * Antes: una query get_posts() por cada tipo de banner (N+1: 5 tipos hoy
	 * = 5 queries por cada carga de home), sin ninguna caché, en cada llamada
	 * al endpoint público (usado en cada home/landing). Se cachea el
	 * resultado completo por (location, lang).
	 *
	 * @return array<string,array{intervalMs:int,slides:array<int,array>}>
	 */
	public static function forLocation( string $location, string $lang ): array {
		$cacheKey = $location . ':' . $lang;
		$cached   = wp_cache_get( $cacheKey, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$out = [];
		foreach ( array_keys( BannerTypes::all() ) as $type ) {
			if ( ! BannerTypes::isLocationAllowed( $type, $location ) ) {
				$out[ $type ] = [ 'intervalMs' => 6000, 'slides' => [] ];
				continue;
			}
			$out[ $type ] = self::cell( $type, $location, $lang );
		}

		wp_cache_set( $cacheKey, $out, self::CACHE_GROUP, self::CACHE_TTL );
		return $out;
	}

	/** @return array{intervalMs:int,slides:array<int,array>} */
	public static function cell( string $type, string $location, string $lang ): array {
		$post = self::resolvePost( $type, $location );
		if ( ! $post ) {
			return [ 'intervalMs' => 6000, 'slides' => [] ];
		}

		$intervalMs = (int) ( get_post_meta( $post->ID, '_hwe_banner_interval_ms', true ) ?: 6000 );
		$rawSlides  = get_post_meta( $post->ID, '_hwe_banner_slides', true );
		$slides     = [];
		if ( is_array( $rawSlides ) ) {
			foreach ( $rawSlides as $i => $raw ) {
				$slide = self::slide( $post, (array) $raw, (string) $i, $type, $location, $lang );
				if ( $slide !== null ) {
					$slides[] = $slide;
				}
			}
		}

		return [ 'intervalMs' => $intervalMs, 'slides' => $slides ];
	}

	/** Post publicado ganador de la celda, o null. */
	public static function resolvePost( string $type, string $location ): ?\WP_Post {
		$posts = get_posts( [
			'post_type'      => PostType::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_hwe_banner_type', 'value' => $type, 'compare' => '=' ],
				[ 'key' => '_hwe_banner_location', 'value' => $location, 'compare' => '=' ],
			],
		] );
		return $posts[0] ?? null;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>|null
	 */
	private static function slide( \WP_Post $post, array $raw, string $suffix, string $bannerType, string $location, string $lang ): ?array {
		$raw         = LegacySlide::upgrade( $raw );
		$isSecondary = $lang !== Plugin::defaultLocale();
		$t           = ( $isSecondary && isset( $raw['i18n'][ $lang ] ) && is_array( $raw['i18n'][ $lang ] ) )
			? $raw['i18n'][ $lang ]
			: [];

		$pick = static fn( string $k ): string => ( ! empty( $t[ $k ] ) ) ? (string) $t[ $k ] : (string) ( $raw[ $k ] ?? '' );
		// Las URL no se traducen por idioma: una sola ruta interna por slide,
		// que el frontend localiza. Un override guardado antes de retirar el
		// campo (p. ej. «/en/store/…») se ignora: era la causa de redirects.
		$href = static fn( string $k ): string => (string) ( $raw[ $k ] ?? '' );

		$type   = (string) ( $raw['type'] ?? SlideTypes::defaultSlug() );
		$fields = SlideTypes::all()[ $type ]['fields'] ?? null;
		$has    = static fn( string $k ): bool => $fields === null || in_array( $k, $fields, true );

		$decorMode = (string) ( $raw['decor_mode'] ?? 'none' );
		if ( ! $has( 'decor_mode' ) || ! in_array( $decorMode, [ 'none', 'default', 'custom' ], true ) ) {
			$decorMode = 'none';
		}
		$decorSvg = (string) ( $raw['decor_svg'] ?? '' );
		if ( $decorMode !== 'custom' ) {
			$decorSvg = '';
		}

		if ( $decorMode === 'default' ) {
			$image  = '';
			$mobile = '';
		} elseif ( $decorMode === 'custom' ) {
			if ( $decorSvg === '' ) {
				return null;
			}
			$image  = '';
			$mobile = '';
		} else {
			$image  = $pick( 'image' );
			$mobile = $pick( 'image_mobile' );
			/* Sin imagen, el slide solo se omite si TAMPOCO tiene copy.

			   Es el mismo criterio que `Save.php` al guardar y que `mapSlide`
			   en el frontend: se descarta lo que no tiene NADA que pintar, no
			   lo que no tiene fotografía. Un slide con titular y botones es
			   servible mientras el cliente no entrega el arte — la plantilla
			   enseña el hueco de rayas—, y omitirlo aquí borraba el mensaje
			   de la portada sin dejar rastro en la base de datos.

			   Los campos se miran con `$has()` además de `$pick()`: un tipo de
			   slide que no declara `title` no puede sostenerse sobre un título
			   que su plantilla no va a leer. */
			$tieneCopy = false;
			foreach ( [ 'title', 'subtitle', 'badge', 'cta' ] as $campo ) {
				if ( $has( $campo ) && trim( $pick( $campo ) ) !== '' ) {
					$tieneCopy = true;
					break;
				}
			}
			if ( $image === '' && ! $tieneCopy ) {
				return null;
			}
		}

		$slide = [
			'id'          => $post->ID . '-' . $suffix,
			'bannerType'  => $bannerType,
			'location'    => $location,
			'order'       => (int) ( $raw['order'] ?? 0 ),
			'image'       => $image,
			'imageMobile' => $has( 'image_mobile' ) && $mobile !== '' ? $mobile : $image,
			// Sin imagen (visual decorativo) no hay nada que describir.
			'imageAlt'    => $has( 'image_alt' ) && $image !== '' ? $pick( 'image_alt' ) : '',
			'title'       => $has( 'title' ) ? $pick( 'title' ) : '',
			'subtitle'    => $has( 'subtitle' ) ? $pick( 'subtitle' ) : '',
			'cta'         => $has( 'cta' ) ? $pick( 'cta' ) : '',
			'ctaHref'     => $has( 'cta_href' ) ? $href( 'cta_href' ) : '',
			'cta2'        => $has( 'cta2' ) ? $pick( 'cta2' ) : '',
			'cta2Href'    => $has( 'cta2_href' ) ? $href( 'cta2_href' ) : '',
			'badge'       => $has( 'badge' ) ? $pick( 'badge' ) : '',
			'link'        => $has( 'link' ) ? $href( 'link' ) : '',
			'hideOverlay' => $has( 'hide_overlay' ) && ! empty( $raw['hide_overlay'] ) && $raw['hide_overlay'] !== '0',
			'type'        => $type,
		];

		if ( $has( 'show_copy' ) ) {
			// Default true si el meta no existe (banners antiguos / primer guardado).
			$rawShow = $raw['show_copy'] ?? '1';
			$slide['showCopy'] = ! ( $rawShow === '0' || $rawShow === 0 || $rawShow === false );
		}

		if ( $has( 'decor_mode' ) ) {
			$slide['decorMode'] = $decorMode;
			if ( $decorMode === 'custom' && $decorSvg !== '' ) {
				$slide['decorSvg'] = $decorSvg;
			}
			// La CLAVE del visual decorativo de plantilla, no una URL: el archivo vive en
			// el frontend (`public/brand/…`), que es quien la resuelve. Solo
			// viaja en modo `default`, el único que la usa. Un slide guardado
			// antes de que existiera el campo no la trae y el frontend cae a su
			// visual de siempre.
			if ( $decorMode === 'default' ) {
				$preset = DecorPresets::sanitize( (string) ( $raw['decor_preset'] ?? '' ) );
				if ( $preset !== '' ) {
					$slide['decorPreset'] = $preset;
				}
			}
		}

		$visible = apply_filters( 'hwe_banners_slide_visible', true, $slide, $post, $lang );
		if ( ! $visible ) {
			return null;
		}

		return apply_filters( 'hwe_banners_slide', $slide, $post, $lang );
	}
}
