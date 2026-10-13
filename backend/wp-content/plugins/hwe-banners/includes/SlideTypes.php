<?php

namespace HWE\Banners;

/**
 * Tipos de plantilla de un slide de banner. Genérico y filtrable: el frontend
 * decide cómo renderiza cada tipo. Una instancia añade/renombra tipos vía el
 * filtro `hwe_banners_slide_types` sin tocar el plugin (mismo patrón que
 * Placements). El núcleo solo conoce el tipo genérico `default`.
 *
 * Cada tipo puede declararse de dos formas en el filtro:
 *   - string:  slug => 'Etiqueta'                    (solo etiqueta, sin más config)
 *   - array:   slug => [ 'label' => …, 'description' => …, 'fields' => [ … ] ]
 * `description` = texto de una línea que describe la estructura de la plantilla.
 * `fields`      = campos del slide que el form debe MOSTRAR para ese tipo
 *                 (null / ausente = mostrar todos). El plugin solo aporta el
 *                 mecanismo; qué campos usa cada plantilla es conocimiento de la
 *                 instancia (depende de sus componentes de frontend).
 */
class SlideTypes {

	/**
	 * @return array<string,array{label:string,description:string,fields:array<int,string>|null}>
	 *   slug => config normalizada.
	 */
	public static function all(): array {
		$defaults = [ 'default' => __( 'Predeterminado', 'hwe-banners' ) ];
		$types    = apply_filters( 'hwe_banners_slide_types', $defaults );

		$clean = [];
		foreach ( (array) $types as $slug => $cfg ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' ) {
				continue;
			}
			$clean[ $slug ] = self::normalize( $slug, $cfg );
		}

		if ( $clean === [] ) {
			$clean['default'] = self::normalize( 'default', __( 'Predeterminado', 'hwe-banners' ) );
		}
		return $clean;
	}

	/**
	 * @param string|array<string,mixed> $cfg
	 * @return array{label:string,description:string,fields:array<int,string>|null}
	 */
	private static function normalize( string $slug, $cfg ): array {
		if ( is_array( $cfg ) ) {
			$fields = ( isset( $cfg['fields'] ) && is_array( $cfg['fields'] ) )
				? array_values( array_filter( array_map( 'sanitize_key', $cfg['fields'] ) ) )
				: null;
			return [
				'label'       => (string) ( $cfg['label'] ?? $slug ),
				'description' => (string) ( $cfg['description'] ?? '' ),
				'fields'      => $fields,
			];
		}
		return [
			'label'       => (string) $cfg,
			'description' => '',
			'fields'      => null,
		];
	}

	public static function isValid( string $slug ): bool {
		return array_key_exists( $slug, self::all() );
	}

	/** El tipo por defecto: `default` si existe, si no la primera clave disponible. */
	public static function defaultSlug(): string {
		$all = self::all();
		return isset( $all['default'] ) ? 'default' : (string) array_key_first( $all );
	}
}
