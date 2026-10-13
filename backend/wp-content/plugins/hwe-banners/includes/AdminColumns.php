<?php

namespace HWE\Banners;

/**
 * Columnas del listado: tipo, locación, vista previa, nº de slides.
 */
class AdminColumns {

	public static function register(): void {
		add_filter( 'manage_' . PostType::SLUG . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . PostType::SLUG . '_posts_custom_column', [ self::class, 'render' ], 10, 2 );
	}

	/** @param array<string,string> $columns @return array<string,string> */
	public static function columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['hwe_type']     = __( 'Tipo', 'hwe-banners' );
				$new['hwe_location'] = __( 'Locación', 'hwe-banners' );
				$new['hwe_preview']  = __( 'Vista previa', 'hwe-banners' );
				$new['hwe_slides']   = __( 'Slides', 'hwe-banners' );
			}
		}
		return $new;
	}

	public static function render( string $column, int $postId ): void {
		switch ( $column ) {
			case 'hwe_type':
				$slug = (string) get_post_meta( $postId, '_hwe_banner_type', true );
				echo esc_html( BannerTypes::labels()[ $slug ] ?? ( $slug ?: '—' ) );
				break;
			case 'hwe_location':
				$slug = (string) get_post_meta( $postId, '_hwe_banner_location', true );
				echo esc_html( $slug !== '' ? Locations::label( $slug ) : '—' );
				break;
			case 'hwe_preview':
				$slides = get_post_meta( $postId, '_hwe_banner_slides', true );
				$first  = is_array( $slides ) && isset( $slides[0]['image'] ) ? (string) $slides[0]['image'] : '';
				echo $first !== ''
					? '<img src="' . esc_url( $first ) . '" alt="" style="max-width:100px;max-height:56px;border-radius:4px">'
					: '—';
				break;
			case 'hwe_slides':
				$slides = get_post_meta( $postId, '_hwe_banner_slides', true );
				echo esc_html( (string) ( is_array( $slides ) ? count( $slides ) : 0 ) );
				break;
		}
	}
}
