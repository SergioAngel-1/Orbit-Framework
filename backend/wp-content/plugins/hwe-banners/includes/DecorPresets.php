<?php

namespace HWE\Banners;

/**
 * Visuales decorativos de plantilla ofrecidos cuando el «Visual lateral» de un slide es
 * «Visual decorativo por defecto» (`decor_mode === 'default'`): un SVG decorativo que la
 * plantilla pinta en lugar de una foto.
 *
 * Genérico y filtrable, mismo patrón que `SlideTypes` y `Locations`: el plugin solo aporta el
 * MECANISMO —una lista de claves con etiqueta, la validación y el campo en el metabox—. QUÉ
 * visuales existen y a qué archivo corresponde cada clave es conocimiento de la instancia: los
 * assets viven en el frontend, que es quien resuelve la clave (`InstanceRegistry.decorPresets`),
 * y el plugin no puede ni debe conocer esas rutas.
 *
 * Una instancia los declara con el filtro `hwe_banners_decor_presets`:
 *
 *     add_filter( 'hwe_banners_decor_presets', fn() => [
 *         'linea-oscuro' => 'Línea · trazo oscuro',
 *         'linea-color'  => 'Línea · policromo',
 *     ] );
 *
 * y el MISMO conjunto de claves en `src/instance/registry.ts` (`decorPresets`): un test de
 * paridad del framework comprueba que ambos lados coinciden. Antes se llamaba
 * `hwe_banners_mandala_presets` (cambio incompatible v1 → v2, ver `docs/UPGRADE.md`).
 *
 * Sin filtro la lista queda VACÍA: el metabox no pinta el select y el modo «por defecto» sigue
 * comportándose como siempre (el frontend elige su asset). Es la degradación correcta para una
 * instancia que tiene un solo visual de marca — un desplegable de una sola opción no es una
 * elección.
 *
 * El primer preset declarado es el que el frontend debe servir cuando el slide no trae ninguno
 * (banners guardados antes de que existiera este campo), así que el ORDEN de la lista es
 * significativo.
 */
class DecorPresets {

	/**
	 * @return array<string,string> clave => etiqueta legible.
	 */
	public static function all(): array {
		$presets = apply_filters( 'hwe_banners_decor_presets', [] );

		$clean = [];
		foreach ( (array) $presets as $key => $label ) {
			$key = sanitize_key( (string) $key );
			if ( $key === '' ) {
				continue;
			}
			$clean[ $key ] = (string) $label;
		}
		return $clean;
	}

	/** Clave por defecto: la primera declarada, o cadena vacía si no hay ninguna. */
	public static function defaultKey(): string {
		$all = self::all();
		return $all === [] ? '' : (string) array_key_first( $all );
	}

	/**
	 * Sanea una clave recibida del formulario. Desconocida o vacía → la clave
	 * por defecto (nunca un valor inventado que el frontend no sepa resolver).
	 */
	public static function sanitize( string $key ): string {
		$key = sanitize_key( $key );
		return array_key_exists( $key, self::all() ) ? $key : self::defaultKey();
	}
}
