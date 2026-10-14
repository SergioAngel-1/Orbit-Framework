<?php
/**
 * Plugin Name: HWE Link Hygiene
 * Description: Retira, al guardar, las marcas de origen que los asistentes de IA
 *              añaden a cada enlace que generan (`utm_source=chatgpt.com`…).
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 *
 * Un texto redactado con ayuda de un asistente trae esa marca en CADA enlace, así que
 * en las analíticas todo ese tráfico INTERNO figuraba como llegado del asistente. Solo
 * se retiran los valores de `utm_source` de la lista (filtro `hwe_link_hygiene_utm_sources`,
 * por defecto `chatgpt.com`); cualquier otro `utm_*` (las campañas propias) se respeta.
 *
 * Actúa sobre el contenido de entradas y páginas y sobre las metas HTML del filtro
 * `hwe_hygiene_meta_keys` (ver hwe-space-hygiene.php; las traducciones las añade
 * `hwe-content-i18n`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Valores de `utm_source` que se retiran. @return list<string> */
function hwe_link_hygiene_sources(): array {
	$sources = apply_filters( 'hwe_link_hygiene_utm_sources', array( 'chatgpt.com' ) );
	return array_values( array_filter( (array) $sources, static fn ( $s ) => is_string( $s ) && $s !== '' ) );
}

/**
 * Quita `utm_source=<origen>` de las URL de un fragmento de HTML.
 *
 * Trabaja sobre el parámetro y no sobre el atributo `href`, a propósito: al guardar,
 * WordPress entrega el contenido CON barras (`href=\"…\"`), y una expresión que dependa
 * de las comillas no lo reconocería. El separador se resuelve según lo que siga al
 * parámetro (y el que lo precede puede ser `?`, `&` o `&amp;`, que es como HTML escribe
 * el `&` entre parámetros):
 *  - le sigue otro (`?utm…&p=1`, o `&amp;`): se conserva el separador y el parámetro
 *    siguiente ocupa su sitio;
 *  - era el último (`?p=1&utm…`, `?utm…#ancla`, `?utm…"`): se retira también el
 *    separador que lo precedía.
 * El valor no puede continuar (`chatgpt.com.br`, `chatgpt.com-x`): esos no son la marca
 * y se dejan como están.
 */
function hwe_link_hygiene_strip_utm( string $html ): string {
	if ( stripos( $html, 'utm_source=' ) === false ) {
		return $html;
	}
	foreach ( hwe_link_hygiene_sources() as $source ) {
		$html = (string) preg_replace_callback(
			'~(\?|&amp;|&)utm_source=' . preg_quote( $source, '~' ) . '(?![A-Za-z0-9._-])(&amp;|&)?~i',
			static fn ( array $m ): string => isset( $m[2] ) && $m[2] !== '' ? $m[1] : '',
			$html
		);
	}
	return $html;
}

/** Entradas y páginas. */
function hwe_link_hygiene_filter_post_data( array $data ): array {
	if ( in_array( $data['post_type'] ?? '', array( 'post', 'page' ), true ) && is_string( $data['post_content'] ?? null ) ) {
		$data['post_content'] = hwe_link_hygiene_strip_utm( $data['post_content'] );
	}
	return $data;
}
add_filter( 'wp_insert_post_data', 'hwe_link_hygiene_filter_post_data' );

add_action(
	'init',
	static function (): void {
		if ( ! function_exists( 'hwe_hygiene_meta_keys' ) ) {
			return;
		}
		foreach ( hwe_hygiene_meta_keys()['html'] as $meta_key ) {
			add_filter( "sanitize_post_meta_{$meta_key}", static fn ( $value ) => is_string( $value ) ? hwe_link_hygiene_strip_utm( $value ) : $value );
		}
	},
	99
);
