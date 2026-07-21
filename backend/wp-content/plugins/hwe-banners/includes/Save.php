<?php

namespace HWE\Banners;

/**
 * Guardado de la meta del banner con verificación de nonce/capacidad y saneo por
 * campo. Reindexa y ordena los slides; descarta los que no tengan imagen base.
 */
class Save {

	public static function register(): void {
		add_action( 'save_post_' . PostType::SLUG, [ self::class, 'save' ], 10, 1 );
	}

	public static function save( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST['hwe_banner_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_banner_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hwe_banner_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$placement = isset( $_POST['hwe_banner_placement'] ) ? sanitize_key( wp_unslash( $_POST['hwe_banner_placement'] ) ) : '';
		if ( ! Placements::isValid( $placement ) ) {
			$placement = Placements::defaultSlug();
		}
		update_post_meta( $postId, '_hwe_banner_placement', $placement );
		update_post_meta( $postId, '_hwe_banner_order', isset( $_POST['hwe_banner_order'] ) ? absint( $_POST['hwe_banner_order'] ) : 0 );
		update_post_meta( $postId, '_hwe_banner_interval_ms', isset( $_POST['hwe_banner_interval_ms'] ) ? max( 1000, absint( $_POST['hwe_banner_interval_ms'] ) ) : 6000 );

		$rawSlides = isset( $_POST['hwe_banner_slides'] ) && is_array( $_POST['hwe_banner_slides'] )
			? wp_unslash( $_POST['hwe_banner_slides'] )
			: [];

		$slides    = [];
		$secondary = Plugin::secondaryLocales();
		foreach ( $rawSlides as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['image'] ) ) {
				continue;
			}
			$slide = [
				'image'        => esc_url_raw( $raw['image'] ?? '' ),
				'image_mobile' => esc_url_raw( $raw['image_mobile'] ?? '' ),
				'title'        => sanitize_text_field( $raw['title'] ?? '' ),
				'subtitle'     => sanitize_text_field( $raw['subtitle'] ?? '' ),
				'cta'          => sanitize_text_field( $raw['cta'] ?? '' ),
				'cta_href'     => self::sanitizeHref( $raw['cta_href'] ?? '' ),
				'badge'        => sanitize_text_field( $raw['badge'] ?? '' ),
				'link'         => self::sanitizeHref( $raw['link'] ?? '' ),
				'order'        => isset( $raw['order'] ) ? absint( $raw['order'] ) : 0,
				'hide_overlay' => empty( $raw['hide_overlay'] ) ? '0' : '1',
				'i18n'         => [],
			];
			$rawI18n = ( isset( $raw['i18n'] ) && is_array( $raw['i18n'] ) ) ? $raw['i18n'] : [];
			foreach ( $secondary as $loc ) {
				$t = is_array( $rawI18n[ $loc ] ?? null ) ? $rawI18n[ $loc ] : [];
				$slide['i18n'][ $loc ] = [
					'image'        => esc_url_raw( $t['image'] ?? '' ),
					'image_mobile' => esc_url_raw( $t['image_mobile'] ?? '' ),
					'title'        => sanitize_text_field( $t['title'] ?? '' ),
					'subtitle'     => sanitize_text_field( $t['subtitle'] ?? '' ),
					'cta'          => sanitize_text_field( $t['cta'] ?? '' ),
				];
			}
			$slides[] = $slide;
		}

		usort( $slides, static fn( $a, $b ) => ( $a['order'] ?: 999 ) <=> ( $b['order'] ?: 999 ) );

		if ( $slides === [] ) {
			delete_post_meta( $postId, '_hwe_banner_slides' );
		} else {
			update_post_meta( $postId, '_hwe_banner_slides', $slides );
		}
	}

	/** Permite rutas relativas internas ("/products") y URLs absolutas. */
	private static function sanitizeHref( string $value ): string {
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		if ( str_starts_with( $value, '/' ) ) {
			return sanitize_text_field( $value );
		}
		return esc_url_raw( $value );
	}
}
