<?php

namespace HWE\Banners;

/**
 * Dispara la revalidación ISR del tag "banners" en el frontend Next.js cuando un banner
 * cambia, por el mismo camino que el resto del framework (`Revalidation::trigger` del Control
 * Center: firma, URL y aviso si falta el secreto).
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
		// Purga la caché local de Serializer::forLocation() (ver su docblock) siempre, aunque no
		// haya secreto de revalidación configurado.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'hwe_banners' );
		}
		if ( class_exists( 'HWE\\ControlCenter\\Revalidation' ) ) {
			\HWE\ControlCenter\Revalidation::trigger( 'banners' );
		}
	}
}
