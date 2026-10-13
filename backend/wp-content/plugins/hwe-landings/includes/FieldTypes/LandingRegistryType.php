<?php

namespace HWE\Landings\FieldTypes;

use HWE\ControlCenter\FieldTypes\FieldType;
use HWE\Landings\Registry;

/**
 * Registro editable de landings: filas `identificador · nombre` (y el tipo, al crear la fila).
 *
 * Se guarda como el texto canónico `identificador | Nombre | tipo` (una landing por línea; ver
 * `Registry`). El formulario envía un array de filas; `wp hwe setup` puede pasar el texto
 * directamente. Un identificador repetido o vacío se descarta.
 *
 * El identificador y el nombre de una fila ya guardada quedan fijos (`readonly`: viajan en el POST
 * con su valor, así que se conservan al guardar el resto del formulario; un `disabled` no habría
 * enviado nada y los habría borrado). El TIPO de una fila guardada se edita en el bloque de
 * contenido de esa landing (campo `registry_type`), que escribe en esta misma fila: dos controles
 * con el mismo `name` compiten por el POST y gana el último del HTML.
 */
final class LandingRegistryType implements FieldType {

	public function id(): string {
		return 'landing_registry';
	}

	public function persistence(): string {
		return self::CONFIG;
	}

	public function render( array $field, string $id, string $name, mixed $value, array $path, array $context = array() ): string {
		$rows = Registry::parse( (string) ( $value ?? '' ) );

		$rowsHtml = '';
		foreach ( $rows as $index => $row ) {
			$rowsHtml .= self::row( $name, (string) $index, $row, true );
		}
		$template = self::row( $name, '__INDEX__', array( 'slug' => '', 'label' => '', 'jsonld' => 'article' ), false );
		$count    = count( $rows );
		$id       = esc_attr( $id );
		$add      = esc_html( '+ Añadir landing' );

		return <<<HTML
<div class="hwe-landing-registry" id="{$id}" data-next-index="{$count}">
    <div class="hwe-landing-registry-rows">{$rowsHtml}</div>
    <button type="button" class="button hwe-landing-registry-add">{$add}</button>
    <script type="text/html" class="hwe-landing-registry-tpl">{$template}</script>
</div>
HTML;
	}

	/** @param array{slug:string,label:string,jsonld:string} $row */
	private static function row( string $name, string $index, array $row, bool $existing ): string {
		$base   = esc_attr( $name ) . '[' . esc_attr( $index ) . ']';
		$slug   = esc_attr( $row['slug'] );
		$label  = esc_attr( $row['label'] );
		$lock   = $existing ? ' readonly' : '';
		$slugTt = esc_attr( $existing
			? 'El identificador no se puede cambiar una vez creada la landing. La URL pública se edita en el bloque de la landing.'
			: 'Identificador de la landing: minúsculas, números y guiones. No podrás cambiarlo después de guardar.' );
		$labelTt = esc_attr( $existing
			? 'El nombre no se edita aquí una vez creada la landing.'
			: 'Nombre visible de la landing (etiqueta de su bloque).' );
		$remove  = esc_attr( 'Quitar' );
		$rowCls  = $existing ? 'hwe-landing-registry-row hwe-landing-registry-row--locked' : 'hwe-landing-registry-row';
		$newAttr = $existing ? '' : ' data-new="1"';

		// El tipo solo se ELIGE aquí al crear la fila: una fila guardada lo edita su propio bloque.
		$type = '';
		if ( ! $existing ) {
			$article = 'article' === $row['jsonld'] ? ' selected' : '';
			$person  = 'person' === $row['jsonld'] ? ' selected' : '';
			$type    = '<select name="' . $base . '[jsonld]" class="hwe-lr-jsonld">'
				. '<option value="article"' . $article . '>Artículo</option>'
				. '<option value="person"' . $person . '>Persona</option>'
				. '</select>';
		}

		return <<<HTML
<div class="{$rowCls}"{$newAttr}>
    <input type="text" name="{$base}[slug]" value="{$slug}" placeholder="identificador-de-la-landing" class="hwe-lr-slug" pattern="[a-z0-9-]+"{$lock} title="{$slugTt}">
    <input type="text" name="{$base}[label]" value="{$label}" placeholder="Nombre visible" class="hwe-lr-label"{$lock} title="{$labelTt}">
    {$type}
    <button type="button" class="button-link hwe-landing-registry-remove" aria-label="{$remove}">&times;</button>
</div>
HTML;
	}

	public function sanitize( array $field, mixed $raw ): mixed {
		if ( is_string( $raw ) ) {
			return Registry::serialize( Registry::parse( $raw ) );
		}
		if ( ! is_array( $raw ) ) {
			return '';
		}

		// Tipo guardado por identificador: una fila sin `jsonld` (p. ej. el bloque de su landing no
		// llegó en el POST) conserva el suyo en vez de volver a «Artículo».
		$stored = array();
		foreach ( Registry::parse( Registry::stored() ) as $row ) {
			$stored[ $row['slug'] ] = $row['jsonld'];
		}

		$rows = array();
		$seen = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $row['slug'] ?? '' ) );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$label         = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$jsonld        = array_key_exists( 'jsonld', $row ) ? $row['jsonld'] : ( $stored[ $slug ] ?? 'article' );
			$rows[]        = array(
				'slug'   => $slug,
				'label'  => '' !== $label ? $label : $slug,
				'jsonld' => Registry::kind( $jsonld ),
			);
		}
		return Registry::serialize( $rows );
	}
}
