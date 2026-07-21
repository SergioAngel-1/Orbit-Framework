<?php

namespace HWE\Banners;

/**
 * Dispara la revalidación ISR del tag "banners" en el frontend Next.js cuando un
 * banner cambia. Reutiliza el mismo secreto/URL que el HWE Control Center. Si las
 * constantes no están definidas, se omite silenciosamente.
 */
class Revalidation {

	public static function register(): void {
		add_action( 'save_post_' . PostType::SLUG, [ self::class, 'onChange' ], 20, 1 );
		add_action( 'deleted_post', [ self::class, 'onDelete' ], 20, 2 );
		add_action( 'trashed_post', [ self::class, 'onDelete' ], 20, 1 );
	}

	public static function onChange( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $postId ) ) {
			return;
		}
		self::trigger();
	}

	public static function onDelete( int $postId, ?\WP_Post $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $postId );
		if ( $type === PostType::SLUG ) {
			self::trigger();
		}
	}

	private static function trigger(): void {
		$secret = defined( 'HWE_REVALIDATION_SECRET' ) ? HWE_REVALIDATION_SECRET : '';
		if ( $secret === '' ) {
			return;
		}
		$frontendUrl = defined( 'HEADLESS_FRONTEND_URL' )
			? rtrim( HEADLESS_FRONTEND_URL, '/' )
			: 'http://localhost:3000';

		$endpoint = $frontendUrl . '/api/revalidate';
		$body     = wp_json_encode( [ 'tag' => 'banners', 'source' => 'hwe-banners' ] );
		$sig      = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		wp_remote_post( $endpoint, [
			'timeout'  => 8,
			'blocking' => false,
			'headers'  => [
				'Content-Type'    => 'application/json',
				'X-HWE-Signature' => $sig,
			],
			'body'     => $body,
		] );
	}
}
