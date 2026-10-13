<?php
/**
 * Plugin Name: HWE Space Hygiene
 * Description: Cambia, al guardar, los espacios duros PEGADOS entre palabras por
 *              espacios normales.
 * Author:      Headless Web Ecosystem
 * Version:     2.0.0
 *
 * Word, Google Docs y los asistentes de IA meten espacios duros al copiar (el carácter
 * U+00A0 o la entidad `&nbsp;`), y un espacio duro no admite salto de línea: un título
 * con todas sus palabras soldadas se parte por la mitad de una palabra y deja huecos, y
 * un cuerpo entero soldado desborda en móvil.
 *
 * En el HTML solo se cambia la entidad que une DOS palabras (`Cada&nbsp;diciembre`): un
 * `&nbsp;` suelto, el de un párrafo vacío o el que el editor escribe para un espacio
 * doble se respeta.
 *
 * Alcance: título de entradas y páginas, cuerpo de las ENTRADAS (los textos legales de
 * las páginas pueden llevar su propia tipografía) y las metas de una línea o de HTML que
 * declare el filtro `hwe_hygiene_meta_keys` (por defecto, el SEO por entidad;
 * `hwe-content-i18n` añade las de cada idioma). Los productos quedan fuera: su título y
 * descripción vienen del catálogo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Espacio duro literal (U+00A0 en UTF-8) → espacio normal. */
function hwe_space_hygiene_fix( string $text ): string {
	return str_contains( $text, "\xC2\xA0" ) ? str_replace( "\xC2\xA0", ' ', $text ) : $text;
}

/**
 * HTML: además del literal, la entidad `&nbsp;` que une dos palabras. Los extremos
 * tienen que ser letra, cifra o puntuación (no `>`/`<`): así un `<p>&nbsp;</p>` o un
 * `&nbsp;` junto a un espacio no se tocan.
 */
function hwe_space_hygiene_fix_html( string $html ): string {
	$html = hwe_space_hygiene_fix( $html );
	if ( ! str_contains( $html, '&nbsp;' ) ) {
		return $html;
	}
	return (string) preg_replace(
		'~(?<=[\p{L}\p{N}.,;:!?»”)\]])&nbsp;(?=[\p{L}\p{N}¿¡«“(\[])~u',
		' ',
		$html
	);
}

/** Igual, y además sin espacios dobles ni en los extremos: para una sola línea. */
function hwe_space_hygiene_fix_line( string $text ): string {
	if ( ! str_contains( $text, "\xC2\xA0" ) ) {
		return $text;
	}
	return trim( (string) preg_replace( '/ {2,}/', ' ', hwe_space_hygiene_fix( $text ) ) );
}

/**
 * Metas de post que pasan por la higiene al guardarse, por formato.
 *
 * Filtro `hwe_hygiene_meta_keys`: lo comparten `hwe-space-hygiene` y
 * `hwe-link-hygiene`. Las traducciones (`_hwe_<campo>_<locale>`) las añade
 * `hwe-content-i18n`; una instancia puede añadir las suyas.
 *
 * @return array{line: list<string>, html: list<string>}
 */
function hwe_hygiene_meta_keys(): array {
	$keys = apply_filters(
		'hwe_hygiene_meta_keys',
		array(
			'line' => array( '_hwe_seo_title', '_hwe_seo_description' ),
			'html' => array(),
		)
	);
	return array(
		'line' => array_values( array_filter( (array) ( $keys['line'] ?? array() ), 'is_string' ) ),
		'html' => array_values( array_filter( (array) ( $keys['html'] ?? array() ), 'is_string' ) ),
	);
}

/** Título de entradas y páginas; cuerpo de las entradas. */
function hwe_space_hygiene_filter_post_data( array $data ): array {
	$type = $data['post_type'] ?? '';
	if ( in_array( $type, array( 'post', 'page' ), true ) && is_string( $data['post_title'] ?? null ) ) {
		$data['post_title'] = hwe_space_hygiene_fix_line( $data['post_title'] );
	}
	if ( 'post' === $type && is_string( $data['post_content'] ?? null ) ) {
		$data['post_content'] = hwe_space_hygiene_fix_html( $data['post_content'] );
	}
	return $data;
}
add_filter( 'wp_insert_post_data', 'hwe_space_hygiene_filter_post_data' );

/**
 * Engancha las metas en `init` (tarde): para entonces cualquier mu-plugin o la
 * instancia ya ha añadido las suyas al filtro.
 */
add_action(
	'init',
	static function (): void {
		$keys = hwe_hygiene_meta_keys();
		foreach ( $keys['line'] as $meta_key ) {
			add_filter( "sanitize_post_meta_{$meta_key}", static fn ( $value ) => is_string( $value ) ? hwe_space_hygiene_fix_line( $value ) : $value );
		}
		foreach ( $keys['html'] as $meta_key ) {
			add_filter( "sanitize_post_meta_{$meta_key}", static fn ( $value ) => is_string( $value ) ? hwe_space_hygiene_fix_html( $value ) : $value );
		}
	},
	99
);
