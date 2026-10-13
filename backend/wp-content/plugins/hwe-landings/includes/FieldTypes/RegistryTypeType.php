<?php

namespace HWE\Landings\FieldTypes;

use HWE\ControlCenter\FieldTypes\FieldType;
use HWE\Landings\Registry;

/**
 * «Tipo de landing» dentro del bloque de contenido de una landing creada desde el panel.
 *
 * No es un valor propio: es el MISMO dato que la 3.ª columna de la fila del registro
 * (`landings.registry`), pintado en otro sitio del formulario. Por eso su saneado devuelve
 * `FieldType::SKIP` (no guarda ningún valor fantasma en `landings.editorial.<id>`) y su control
 * se llama `hwe_config[landings][registry][<índice>][jsonld]`: sin ese prefijo el valor viajaría
 * en una clave del POST que el guardado nunca mira y el selector no guardaría nada.
 */
final class RegistryTypeType implements FieldType {

	public function id(): string {
		return 'registry_type';
	}

	public function persistence(): string {
		return self::CONFIG;
	}

	public function render( array $field, string $id, string $name, mixed $value, array $path, array $context = array() ): string {
		$index   = (int) ( $field['registry_index'] ?? 0 );
		$current = Registry::kind( $field['current_value'] ?? 'article' );
		$root    = (string) ( $path[0] ?? 'landings' );
		$control = esc_attr( "hwe_config[{$root}][registry][{$index}][jsonld]" );
		$id      = esc_attr( $id );
		$article = 'article' === $current ? ' selected' : '';
		$person  = 'person' === $current ? ' selected' : '';

		return '<select id="' . $id . '" name="' . $control . '" class="hwe-lr-jsonld">'
			. '<option value="article"' . $article . '>Artículo</option>'
			. '<option value="person"' . $person . '>Persona</option>'
			. '</select>';
	}

	public function sanitize( array $field, mixed $raw ): mixed {
		return self::SKIP;
	}
}
