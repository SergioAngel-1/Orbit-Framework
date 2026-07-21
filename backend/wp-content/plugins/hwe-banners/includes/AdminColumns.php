<?php

namespace HWE\Banners;

/**
 * Columnas personalizadas en el listado de banners: posición, vista previa,
 * número de slides y orden.
 */
class AdminColumns {

	public static function register(): void {
		add_filter( 'manage_' . PostType::SLUG . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . PostType::SLUG . '_posts_custom_column', [ self::class, 'render' ], 10, 2 );
		add_filter( 'manage_edit-' . PostType::SLUG . '_sortable_columns', [ self::class, 'sortable' ] );
		add_action( 'pre_get_posts', [ self::class, 'orderby' ] );
	}

	/** @param array<string,string> $columns @return array<string,string> */
	public static function columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['hwe_placement'] = __( 'Posición', 'hwe-banners' );
				$new['hwe_preview']   = __( 'Vista previa', 'hwe-banners' );
				$new['hwe_slides']    = __( 'Slides', 'hwe-banners' );
				$new['hwe_order']     = __( 'Orden', 'hwe-banners' );
			}
		}
		return $new;
	}

	public static function render( string $column, int $postId ): void {
		switch ( $column ) {
			case 'hwe_placement':
				$slug   = (string) get_post_meta( $postId, '_hwe_banner_placement', true );
				$labels = Placements::all();
				echo esc_html( $labels[ $slug ] ?? $slug ?: '—' );
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
			case 'hwe_order':
				echo esc_html( (string) (int) get_post_meta( $postId, '_hwe_banner_order', true ) );
				break;
		}
	}

	/** @param array<string,string> $columns @return array<string,string> */
	public static function sortable( array $columns ): array {
		$columns['hwe_order'] = 'hwe_order';
		return $columns;
	}

	public static function orderby( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'orderby' ) === 'hwe_order' ) {
			$query->set( 'meta_key', '_hwe_banner_order' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}
}
