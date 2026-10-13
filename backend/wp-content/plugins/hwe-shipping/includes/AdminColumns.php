<?php

namespace HWE\Shipping;

/**
 * Columnas de listado: Estado + Valor agregado (métodos), Tarifa + Envío
 * gratis (ciudades).
 */
class AdminColumns {

	public static function register(): void {
		add_filter( 'manage_' . PostTypes::METHOD_SLUG . '_posts_columns', [ self::class, 'methodColumns' ] );
		add_action( 'manage_' . PostTypes::METHOD_SLUG . '_posts_custom_column', [ self::class, 'renderMethod' ], 10, 2 );
		add_filter( 'manage_' . PostTypes::RATE_SLUG . '_posts_columns', [ self::class, 'rateColumns' ] );
		add_action( 'manage_' . PostTypes::RATE_SLUG . '_posts_custom_column', [ self::class, 'renderRate' ], 10, 2 );
	}

	/** @param array<string,string> $columns @return array<string,string> */
	public static function methodColumns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['hwe_enabled']     = __( 'Estado', 'hwe-shipping' );
				$new['hwe_added_value'] = __( 'Valor agregado', 'hwe-shipping' );
			}
		}
		return $new;
	}

	public static function renderMethod( string $column, int $postId ): void {
		switch ( $column ) {
			case 'hwe_enabled':
				$enabled = get_post_meta( $postId, '_hwe_shipping_method_enabled', true );
				$enabled = $enabled === '' ? true : $enabled === '1';
				echo $enabled
					? '<span style="color:#0a7d3c;font-weight:600">' . esc_html__( 'Activo', 'hwe-shipping' ) . '</span>'
					: '<span style="color:#8a8a8a">' . esc_html__( 'Inactivo', 'hwe-shipping' ) . '</span>';
				break;
			case 'hwe_added_value':
				$added = (string) get_post_meta( $postId, '_hwe_shipping_method_added_value', true );
				echo esc_html( $added !== '' && $added !== '0' ? $added : '—' );
				break;
		}
	}

	/** @param array<string,string> $columns @return array<string,string> */
	public static function rateColumns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $key === 'title' ? __( 'Ciudad', 'hwe-shipping' ) : $label;
			if ( $key === 'title' ) {
				$new['hwe_amount'] = __( 'Tarifa', 'hwe-shipping' );
				$new['hwe_free']   = __( 'Envío gratis', 'hwe-shipping' );
			}
		}
		return $new;
	}

	public static function renderRate( string $column, int $postId ): void {
		if ( $column === 'hwe_amount' ) {
			echo esc_html( (string) get_post_meta( $postId, '_hwe_shipping_rate_amount', true ) );
		}
		if ( $column === 'hwe_free' ) {
			echo get_post_meta( $postId, Save::RATE_FREE_META, true ) === '1'
				? '<span style="color:#0a7d3c;font-weight:600">' . esc_html__( 'Aplica', 'hwe-shipping' ) . '</span>'
				: '<span style="color:#8a8a8a">—</span>';
		}
	}
}
