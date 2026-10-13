<?php

namespace HWE\Shipping;

/**
 * Guardado de la meta de ambos CPTs.
 */
class Save {

	/** Casilla «Aplica envío gratis» de una tarifa de ciudad ('1' | '0'). */
	public const RATE_FREE_META = '_hwe_shipping_rate_free_eligible';

	public static function register(): void {
		add_action( 'save_post_' . PostTypes::METHOD_SLUG, [ self::class, 'saveMethod' ], 10, 1 );
		add_action( 'save_post_' . PostTypes::RATE_SLUG, [ self::class, 'saveRate' ], 10, 1 );
	}

	public static function saveMethod( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST['hwe_shipping_method_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_shipping_method_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hwe_shipping_method_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$enabled = ! empty( $_POST['hwe_shipping_method_enabled'] ) && $_POST['hwe_shipping_method_enabled'] === '1';
		update_post_meta( $postId, '_hwe_shipping_method_enabled', $enabled ? '1' : '0' );

		$added = isset( $_POST['hwe_shipping_method_added_value'] ) ? self::sanitizeAmount( (string) $_POST['hwe_shipping_method_added_value'] ) : '';
		if ( $added === '' ) {
			delete_post_meta( $postId, '_hwe_shipping_method_added_value' );
		} else {
			update_post_meta( $postId, '_hwe_shipping_method_added_value', $added );
		}
	}

	public static function saveRate( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$nonce = isset( $_POST['hwe_shipping_rate_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hwe_shipping_rate_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hwe_shipping_rate_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$amount = isset( $_POST['hwe_shipping_rate_amount'] ) ? self::sanitizeAmount( (string) $_POST['hwe_shipping_rate_amount'] ) : '';
		update_post_meta( $postId, '_hwe_shipping_rate_amount', $amount === '' ? '0' : $amount );

		$free = ! empty( $_POST['hwe_shipping_rate_free_eligible'] ) && $_POST['hwe_shipping_rate_free_eligible'] === '1';
		update_post_meta( $postId, self::RATE_FREE_META, $free ? '1' : '0' );
	}

	/** Entero no negativo como string; '' si no es numérico. */
	private static function sanitizeAmount( string $raw ): string {
		$raw = trim( wp_unslash( $raw ) );
		if ( $raw === '' || ! is_numeric( $raw ) ) {
			return '';
		}
		$n = max( 0, (int) round( (float) $raw ) );
		return (string) $n;
	}
}
