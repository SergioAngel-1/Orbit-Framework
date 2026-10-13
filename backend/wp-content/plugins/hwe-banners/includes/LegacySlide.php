<?php

namespace HWE\Banners;

/**
 * Compatibilidad con los slides guardados antes de v2.
 *
 * El visual decorativo de un slide se guardaba como `mandala_mode`, `mandala_preset` y
 * `mandala_svg`; ahora es `decor_mode`, `decor_preset` y `decor_svg`. Un slide viejo se lee con
 * las claves nuevas sin tocar la base de datos (la próxima vez que se guarde el banner se
 * escribe con las nuevas), así que no hay migración de datos.
 */
class LegacySlide {

	private const RENAMED = [
		'mandala_mode'   => 'decor_mode',
		'mandala_preset' => 'decor_preset',
		'mandala_svg'    => 'decor_svg',
	];

	/**
	 * @param array<string,mixed> $raw Slide tal como está guardado.
	 * @return array<string,mixed> El mismo slide con las claves actuales (las nuevas mandan).
	 */
	public static function upgrade( array $raw ): array {
		foreach ( self::RENAMED as $old => $new ) {
			if ( array_key_exists( $old, $raw ) ) {
				if ( ! array_key_exists( $new, $raw ) ) {
					$raw[ $new ] = $raw[ $old ];
				}
				unset( $raw[ $old ] );
			}
		}
		return $raw;
	}
}
