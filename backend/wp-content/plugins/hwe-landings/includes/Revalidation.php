<?php

namespace HWE\Landings;

/**
 * Revalida el tag ISR `landings` del frontend cuando cambia algo del grupo `landings` del
 * Control Center, y lo declara en `hwe_revalidation_tags` (sin esto el núcleo no envía su
 * revalidación).
 *
 * El contenido viaja en la config pública, que el Control Center ya revalida (`site-config`) en
 * cada guardado; este tag es el propio del módulo, para lo que el frontend lea aparte.
 */
final class Revalidation {

	public const TAG = 'landings';

	public static function register(): void {
		add_filter( 'hwe_revalidation_tags', array( self::class, 'tags' ) );
		add_action( 'hwe_control_center_saved', array( self::class, 'onSaved' ), 10, 1 );
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

	/** @param mixed $publicData Lo público saneado del guardado del Control Center. */
	public static function onSaved( $publicData ): void {
		if ( ! is_array( $publicData ) || ! isset( $publicData['landings'] ) ) {
			return;
		}
		if ( class_exists( 'HWE\\ControlCenter\\Revalidation' ) ) {
			\HWE\ControlCenter\Revalidation::trigger( self::TAG );
		}
	}
}
