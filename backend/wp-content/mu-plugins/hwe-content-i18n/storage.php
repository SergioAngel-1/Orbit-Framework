<?php
/**
 * Lectura, saneado y guardado de las traducciones (metas de post y de término).
 *
 * Reglas:
 * - Solo se escribe un idioma que el sitio tiene configurado y un campo que el tipo declara:
 *   un valor de la petición nunca decide el nombre de una meta.
 * - Vacío = se borra la meta y el frontend usa el contenido nativo.
 * - El slug traducido no puede coincidir con el slug nativo de OTRA ficha del mismo tipo
 *   ni con el slug traducido (en cualquier idioma) de otra: la URL `/<idioma>/…/<x>` tiene
 *   que resolver a una sola ficha. Igual al nativo = sin traducción.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Prefijo de las metas de slug traducido (la búsqueda de conflictos no depende del idioma). */
const HWE_I18N_SLUG_META_PREFIX = '_hwe_slug_';

/** Valor guardado de un campo en un idioma, o '' si no hay. */
function hwe_i18n_get( string $kind, int $id, string $field, string $suffix ): string {
	$key   = hwe_i18n_meta_key( $field, $suffix );
	$value = 'term' === $kind ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
	return is_string( $value ) ? $value : '';
}

/** Limpia un valor del formulario según la entrada del campo y su largo máximo. */
function hwe_i18n_sanitize( array $spec, string $raw ): string {
	switch ( $spec['input'] ) {
		case 'html':
			$clean = wp_kses_post( $raw );
			break;
		case 'textarea':
			$clean = sanitize_textarea_field( $raw );
			break;
		case 'slug':
			$clean = sanitize_title( $raw );
			break;
		default:
			$clean = sanitize_text_field( $raw );
	}
	$clean = is_string( $clean ) ? $clean : '';
	if ( $spec['max'] > 0 ) {
		$clean = mb_substr( $clean, 0, $spec['max'] );
	}
	return trim( $clean );
}

/**
 * Lee del formulario las traducciones enviadas para un tipo, tras comprobar el nonce.
 *
 * @param string $nonce_name   Nombre del campo del nonce.
 * @param string $nonce_action Acción del nonce.
 * @return array<string,array<string,string>>|null sufijo => campo => valor crudo, o null
 *         si falta o es inválido el nonce.
 */
function hwe_i18n_read_input( string $type, string $nonce_name, string $nonce_action ): ?array {
	if ( ! isset( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), $nonce_action ) ) {
		return null;
	}
	$fields  = hwe_i18n_fields_for( $type );
	$locales = hwe_i18n_locales();
	$posted  = isset( $_POST['hwe_i18n'] ) && is_array( $_POST['hwe_i18n'] ) ? wp_unslash( $_POST['hwe_i18n'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- nonce verificado arriba; se sanea por campo.

	$input = array();
	foreach ( $locales as $suffix ) {
		if ( ! isset( $posted[ $suffix ] ) || ! is_array( $posted[ $suffix ] ) ) {
			continue;
		}
		foreach ( $fields as $name => $spec ) {
			if ( isset( $posted[ $suffix ][ $name ] ) && is_string( $posted[ $suffix ][ $name ] ) ) {
				$input[ $suffix ][ $name ] = $posted[ $suffix ][ $name ];
			}
		}
	}
	return $input;
}

/** Escribe (o borra, si está vacío) la traducción de un campo. */
function hwe_i18n_write( string $kind, int $id, string $field, string $suffix, string $value ): void {
	$key = hwe_i18n_meta_key( $field, $suffix );
	if ( '' === $value ) {
		'term' === $kind ? delete_term_meta( $id, $key ) : delete_post_meta( $id, $key );
		return;
	}
	// `update_*_meta` hace `wp_unslash` del valor: sin `wp_slash`, una barra invertida
	// escrita a mano se corrompe en cada guardado.
	'term' === $kind ? update_term_meta( $id, $key, wp_slash( $value ) ) : update_post_meta( $id, $key, wp_slash( $value ) );
}

/**
 * Valida un slug traducido de un post. `[slug, error]`: slug '' sin error = sin traducción
 * (vacío o igual al nativo); un error no vacío = conflicto (no se guarda).
 *
 * @return array{0:string,1:string}
 */
function hwe_i18n_validate_post_slug( int $post_id, \WP_Post $post, string $slug ): array {
	if ( '' === $slug || $slug === $post->post_name ) {
		return array( '', '' );
	}
	global $wpdb;

	$native = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND ID <> %d AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
			$post->post_type,
			$slug,
			$post_id
		)
	);
	if ( $native ) {
		/* translators: 1: slug, 2: ID de la otra ficha */
		return array( '', sprintf( __( '«%1$s» ya es el slug de otra ficha (ID %2$d).', 'hwe' ), $slug, (int) $native ) );
	}

	$other = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s AND pm.meta_value = %s AND p.post_type = %s AND pm.post_id <> %d AND p.post_status NOT IN ('trash','auto-draft') LIMIT 1",
			$wpdb->esc_like( HWE_I18N_SLUG_META_PREFIX ) . '%',
			$slug,
			$post->post_type,
			$post_id
		)
	);
	if ( $other ) {
		/* translators: 1: slug, 2: ID de la otra ficha */
		return array( '', sprintf( __( '«%1$s» ya es un slug traducido de otra ficha (ID %2$d).', 'hwe' ), $slug, (int) $other ) );
	}

	return array( $slug, '' );
}

/**
 * Igual que `hwe_i18n_validate_post_slug`, para un término de `$taxonomy`.
 *
 * @return array{0:string,1:string}
 */
function hwe_i18n_validate_term_slug( int $term_id, string $taxonomy, string $slug ): array {
	$term = get_term( $term_id, $taxonomy );
	if ( ! $term instanceof \WP_Term ) {
		return array( '', __( 'La categoría no existe.', 'hwe' ) );
	}
	if ( '' === $slug || $slug === $term->slug ) {
		return array( '', '' );
	}
	global $wpdb;

	$native = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug = %s AND t.term_id <> %d LIMIT 1",
			$taxonomy,
			$slug,
			$term_id
		)
	);
	if ( $native ) {
		/* translators: 1: slug, 2: ID del otro término */
		return array( '', sprintf( __( '«%1$s» ya es el slug de otro término (ID %2$d).', 'hwe' ), $slug, (int) $native ) );
	}

	$other = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT tm.term_id FROM {$wpdb->termmeta} tm JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_key LIKE %s AND tm.meta_value = %s AND tt.taxonomy = %s AND tm.term_id <> %d LIMIT 1",
			$wpdb->esc_like( HWE_I18N_SLUG_META_PREFIX ) . '%',
			$slug,
			$taxonomy,
			$term_id
		)
	);
	if ( $other ) {
		/* translators: 1: slug, 2: ID del otro término */
		return array( '', sprintf( __( '«%1$s» ya es un slug traducido de otro término (ID %2$d).', 'hwe' ), $slug, (int) $other ) );
	}

	return array( $slug, '' );
}

/**
 * Guarda las traducciones de un post desde su caja (hook `save_post`).
 *
 * Un conflicto de slug conserva el valor anterior (no borra una URL ya publicada) y deja
 * el aviso para la siguiente carga de la ficha.
 */
function hwe_i18n_save_post( int $post_id, \WP_Post $post ): void {
	$fields = hwe_i18n_fields_for( $post->post_type );
	$type   = hwe_i18n_registry()[ $post->post_type ] ?? null;
	if ( array() === $fields || null === $type || 'post' !== $type['kind'] ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$input = hwe_i18n_read_input( $post->post_type, 'hwe_i18n_nonce', 'hwe_i18n_save_post' );
	if ( null === $input ) {
		return;
	}

	$errors = array();
	foreach ( $input as $suffix => $values ) {
		foreach ( $values as $name => $raw ) {
			$value = hwe_i18n_sanitize( $fields[ $name ], $raw );
			if ( 'slug' === $fields[ $name ]['input'] ) {
				[ $value, $error ] = hwe_i18n_validate_post_slug( $post_id, $post, $value );
				if ( '' !== $error ) {
					$errors[] = strtoupper( $suffix ) . ': ' . $error;
					continue;
				}
			}
			hwe_i18n_write( 'post', $post_id, $name, $suffix, $value );
		}
	}

	if ( array() !== $errors ) {
		set_transient( 'hwe_i18n_error_' . $post_id . '_' . get_current_user_id(), $errors, 60 );
	}
}

/**
 * Guarda las traducciones de un término desde su formulario (hooks `created_<tax>` y
 * `edited_<tax>`), y pide al frontend que invalide su tag.
 */
function hwe_i18n_save_term( int $term_id, string $taxonomy ): void {
	$fields = hwe_i18n_fields_for( $taxonomy );
	$type   = hwe_i18n_registry()[ $taxonomy ] ?? null;
	if ( array() === $fields || null === $type || 'term' !== $type['kind'] ) {
		return;
	}
	if ( ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}
	$input = hwe_i18n_read_input( $taxonomy, 'hwe_i18n_term_nonce', 'hwe_i18n_save_term' );
	if ( null === $input ) {
		return;
	}

	$errors = array();
	foreach ( $input as $suffix => $values ) {
		foreach ( $values as $name => $raw ) {
			$value = hwe_i18n_sanitize( $fields[ $name ], $raw );
			if ( 'slug' === $fields[ $name ]['input'] ) {
				[ $value, $error ] = hwe_i18n_validate_term_slug( $term_id, $taxonomy, $value );
				if ( '' !== $error ) {
					$errors[] = strtoupper( $suffix ) . ': ' . $error;
					continue;
				}
			}
			hwe_i18n_write( 'term', $term_id, $name, $suffix, $value );
		}
	}

	if ( array() !== $errors ) {
		set_transient( 'hwe_i18n_term_error_' . $term_id . '_' . get_current_user_id(), $errors, 60 );
	}
	hwe_i18n_revalidate( $type['revalidate'] );
}

/**
 * Pide al frontend invalidar un tag, una vez por petición. El tag `posts` comparte el
 * candado de `hwe-post-revalidate` (que ya reacciona a las categorías y etiquetas).
 */
function hwe_i18n_revalidate( ?string $tag ): void {
	static $done = array();
	if ( null === $tag || isset( $done[ $tag ] ) ) {
		return;
	}
	$done[ $tag ] = true;

	if ( 'posts' === $tag && function_exists( 'hwe_post_revalidate_now' ) ) {
		hwe_post_revalidate_now();
		return;
	}
	if ( class_exists( 'HWE\ControlCenter\Revalidation' ) ) {
		\HWE\ControlCenter\Revalidation::trigger( $tag );
	}
}
