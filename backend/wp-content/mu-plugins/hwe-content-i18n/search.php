<?php
/**
 * Búsqueda y higiene de `hwe-content-i18n`.
 *
 * - La búsqueda de WPGraphQL (`search`) es un `WP_Query` con `s`, que solo compara
 *   `post_title`, `post_excerpt` y `post_content`: nunca las metas traducidas. Sin este
 *   filtro, buscar en `/<idioma>/blog` una palabra que solo existe en la traducción da cero
 *   resultados aunque la ficha la tenga.
 * - Las metas traducidas pasan por la higiene de espacios (`hwe-space-hygiene`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Añade a la búsqueda de un tipo traducible un OR sobre las metas traducidas (campos con
 * `search` del registro, en cada idioma secundario).
 *
 * `$search` llega como `" AND (…)"` ya aislado del resto del WHERE (`posts_search`), así que
 * basta envolver su interior sin tocar `post_status` ni `post_type`.
 *
 * @param string    $search SQL del fragmento de búsqueda.
 * @param \WP_Query $query  Consulta.
 */
function hwe_i18n_filter_posts_search( string $search, $query ): string {
	if ( is_admin() || '' === $search || ! is_object( $query ) || ! $query->is_search() ) {
		return $search;
	}
	$locales = hwe_i18n_locales();
	if ( array() === $locales ) {
		return $search;
	}

	// WPGraphQL envuelve `post_type` en un array incluso para un solo tipo.
	$post_type  = $query->get( 'post_type' );
	$post_types = is_array( $post_type ) ? array_values( $post_type ) : array( $post_type );
	if ( 1 !== count( $post_types ) || ! is_string( $post_types[0] ) ) {
		return $search;
	}
	$def = hwe_i18n_registry()[ $post_types[0] ] ?? null;
	if ( null === $def || 'post' !== $def['kind'] ) {
		return $search;
	}

	$keys = array();
	foreach ( $def['fields'] as $name => $spec ) {
		if ( ! $spec['search'] ) {
			continue;
		}
		foreach ( $locales as $suffix ) {
			$keys[] = hwe_i18n_meta_key( $name, $suffix );
		}
	}
	$term = trim( (string) $query->get( 's' ) );
	if ( array() === $keys || '' === $term ) {
		return $search;
	}

	global $wpdb;
	$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$like         = '%' . $wpdb->esc_like( $term ) . '%';
	$meta_match   = $wpdb->prepare(
		"{$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value LIKE %s )", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders -- placeholders generados.
		array_merge( $keys, array( $like ) )
	);

	if ( 1 === preg_match( '/^(\s*AND\s*)\((.*)\)\s*$/is', $search, $m ) ) {
		return $m[1] . '((' . $m[2] . ') OR ' . $meta_match . ')';
	}
	return $search;
}
add_filter( 'posts_search', 'hwe_i18n_filter_posts_search', 10, 2 );

/**
 * Metas traducidas que pasan por la higiene de espacios: líneas (texto y texto largo) y
 * HTML. El slug no entra: ya sale de `sanitize_title`.
 *
 * @param mixed $keys `array{line:list<string>,html:list<string>}` del filtro.
 * @return mixed
 */
function hwe_i18n_hygiene_meta_keys( $keys ) {
	$locales = hwe_i18n_locales();
	if ( array() === $locales || ! is_array( $keys ) ) {
		return $keys;
	}
	$line = (array) ( $keys['line'] ?? array() );
	$html = (array) ( $keys['html'] ?? array() );

	foreach ( hwe_i18n_registry() as $def ) {
		if ( 'post' !== $def['kind'] ) {
			continue;
		}
		foreach ( $def['fields'] as $name => $spec ) {
			if ( 'slug' === $spec['input'] ) {
				continue;
			}
			foreach ( $locales as $suffix ) {
				$key = hwe_i18n_meta_key( $name, $suffix );
				if ( 'html' === $spec['input'] ) {
					$html[] = $key;
				} else {
					$line[] = $key;
				}
			}
		}
	}

	$keys['line'] = array_values( array_unique( $line ) );
	$keys['html'] = array_values( array_unique( $html ) );
	return $keys;
}
add_filter( 'hwe_hygiene_meta_keys', 'hwe_i18n_hygiene_meta_keys' );
