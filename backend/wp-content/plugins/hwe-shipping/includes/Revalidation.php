<?php

namespace HWE\Shipping;

/**
 * Revalida el tag ISR `shipping` en el frontend cuando un método o una tarifa cambian, y lo
 * declara en `hwe_revalidation_tags` (sin esto el núcleo no envía su revalidación).
 */
class Revalidation {

	public const TAG = 'shipping';

	public static function register(): void {
		add_filter( 'hwe_revalidation_tags', array( self::class, 'tags' ) );
		add_action( 'save_post_' . PostTypes::METHOD_SLUG, array( self::class, 'onChange' ), 20, 1 );
		add_action( 'save_post_' . PostTypes::RATE_SLUG, array( self::class, 'onChange' ), 20, 1 );
		add_action( 'deleted_post', array( self::class, 'onDelete' ), 20, 2 );
		add_action( 'trashed_post', array( self::class, 'onDelete' ), 20, 1 );
	}

	/**
	 * @param mixed $tags
	 * @return list<string>
	 */
	public static function tags( $tags ): array {
		$tags = is_array( $tags ) ? array_values( $tags ) : array();
		if ( ! in_array( self::TAG, $tags, true ) ) {
			$tags[] = self::TAG;
		}
		return $tags;
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
		if ( PostTypes::METHOD_SLUG === $type || PostTypes::RATE_SLUG === $type ) {
			self::trigger();
		}
	}

	private static function trigger(): void {
		if ( class_exists( 'HWE\\ControlCenter\\Revalidation' ) ) {
			\HWE\ControlCenter\Revalidation::trigger( self::TAG );
		}
	}
}
