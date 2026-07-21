<?php

namespace HWE\Banners;

/**
 * Convierte los banners almacenados en la forma pública que consume el frontend,
 * resolviendo los overrides por idioma y aplicando hooks de extensibilidad.
 */
class Serializer {

	/** @return array<string,array{intervalMs:int,slides:array<int,array>}> */
	public static function all( string $lang ): array {
		$out = [];
		foreach ( array_keys( Placements::all() ) as $slug ) {
			$out[ $slug ] = self::placement( $slug, $lang );
		}
		return $out;
	}

	/** @return array{intervalMs:int,slides:array<int,array>} */
	public static function placement( string $placement, string $lang ): array {
		$posts = get_posts( [
			'post_type'      => PostType::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_hwe_banner_order',
			'order'          => 'ASC',
			'meta_query'     => [
				[ 'key' => '_hwe_banner_placement', 'value' => $placement, 'compare' => '=' ],
			],
		] );

		$intervalMs = 6000;
		$slides     = [];

		foreach ( $posts as $post ) {
			$intervalMs = (int) ( get_post_meta( $post->ID, '_hwe_banner_interval_ms', true ) ?: $intervalMs );
			$rawSlides  = get_post_meta( $post->ID, '_hwe_banner_slides', true );
			if ( ! is_array( $rawSlides ) ) {
				continue;
			}
			foreach ( $rawSlides as $i => $raw ) {
				$slide = self::slide( $post, (array) $raw, (string) $i, $placement, $lang );
				if ( $slide !== null ) {
					$slides[] = $slide;
				}
			}
		}

		return [ 'intervalMs' => $intervalMs, 'slides' => $slides ];
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>|null
	 */
	private static function slide( \WP_Post $post, array $raw, string $suffix, string $placement, string $lang ): ?array {
		$isSecondary = $lang !== Plugin::defaultLocale();
		$t           = ( $isSecondary && isset( $raw['i18n'][ $lang ] ) && is_array( $raw['i18n'][ $lang ] ) )
			? $raw['i18n'][ $lang ]
			: [];

		$pick  = static fn( string $k ): string => ( ! empty( $t[ $k ] ) ) ? (string) $t[ $k ] : (string) ( $raw[ $k ] ?? '' );

		$image = $pick( 'image' );
		if ( $image === '' ) {
			return null;
		}
		$mobile = $pick( 'image_mobile' );

		$slide = [
			'id'          => $post->ID . '-' . $suffix,
			'placement'   => $placement,
			'order'       => (int) ( $raw['order'] ?? 0 ),
			'image'       => $image,
			'imageMobile' => $mobile !== '' ? $mobile : $image,
			'title'       => $pick( 'title' ),
			'subtitle'    => $pick( 'subtitle' ),
			'cta'         => $pick( 'cta' ),
			'ctaHref'     => (string) ( $raw['cta_href'] ?? '' ),
			'badge'       => (string) ( $raw['badge'] ?? '' ),
			'link'        => (string) ( $raw['link'] ?? '' ),
			'hideOverlay' => ! empty( $raw['hide_overlay'] ) && $raw['hide_overlay'] !== '0',
		];

		$visible = apply_filters( 'hwe_banners_slide_visible', true, $slide, $post, $lang );
		if ( ! $visible ) {
			return null;
		}

		return apply_filters( 'hwe_banners_slide', $slide, $post, $lang );
	}
}
