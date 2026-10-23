<?php

namespace HWE\Shipping;

use HWE\Shipping\Rates\Cities;
use HWE\Shipping\Rates\City;

/**
 * Columnas de listado: Estado + Valor agregado (métodos), Tipo + Nº de opciones (ciudades).
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
				$new['hwe_type']    = __( 'Tipo', 'hwe-shipping' );
				$new['hwe_options'] = __( 'Opciones', 'hwe-shipping' );
			}
		}
		return $new;
	}

	public static function renderRate( string $column, int $postId ): void {
		$type = (string) get_post_meta( $postId, Cities::META_TYPE, true );
		if ( $column === 'hwe_type' ) {
			$labels = [
				City::TYPE_FIXED    => __( 'Tarifa fija', 'hwe-shipping' ),
				City::TYPE_DISTANCE => __( 'Por distancia', 'hwe-shipping' ),
			];
			echo isset( $labels[ $type ] )
				? esc_html( $labels[ $type ] )
				: '<span style="color:#b32d2e">' . esc_html__( 'Sin configurar (modelo anterior)', 'hwe-shipping' ) . '</span>';
		}
		if ( $column === 'hwe_options' ) {
			$options = json_decode( (string) get_post_meta( $postId, Cities::META_OPTIONS, true ), true );
			echo esc_html( is_array( $options ) && $options !== [] ? (string) count( $options ) : '—' );
		}
	}
}
