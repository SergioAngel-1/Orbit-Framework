<?php
/**
 * Piezas comunes del SEO por entidad (entradas, páginas, productos y categorías).
 *
 * Los mu-plugins `hwe-{post,page,product,category}-seo.php` solo declaran su entidad
 * (tipo de contenido, textos, tipo GraphQL) y delegan aquí el formulario, el guardado y el
 * registro en WPGraphQL. Así las cuatro cajas se comportan igual (mismos límites, mismo
 * contador, mismas metas) y lo que se añada después (las variantes por idioma de
 * `hwe-content-i18n`) se hace en un único sitio.
 *
 * WordPress no carga las subcarpetas de `mu-plugins/`: cada archivo de entidad incluye
 * este con `require_once`.
 *
 * Metas: `_hwe_seo_title` y `_hwe_seo_description` (de post o de término). Vacío = se
 * borra la meta y el frontend usa el título/resumen nativos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Largos que Google enseña sin cortar: el `<title>` hacia los 60 caracteres, la meta
 * description hacia los 155–160. Los mínimos solo pintan el contador en ámbar.
 */
const HWE_SEO_TITLE_MIN = 50;
const HWE_SEO_TITLE_MAX = 60;
const HWE_SEO_DESC_MIN  = 120;
const HWE_SEO_DESC_MAX  = 160;

/**
 * Campos SEO: clave => [meta, máximo, mínimo, ¿textarea?].
 *
 * @return array<string, array{0:string,1:int,2:int,3:bool}>
 */
function hwe_seo_fields(): array {
	return array(
		'title'       => array( '_hwe_seo_title', HWE_SEO_TITLE_MAX, HWE_SEO_TITLE_MIN, false ),
		'description' => array( '_hwe_seo_description', HWE_SEO_DESC_MAX, HWE_SEO_DESC_MIN, true ),
	);
}

/** Nombre del input de un campo para una entidad (`hwe_post_seo_title`). */
function hwe_seo_input_name( string $entity, string $field ): string {
	return 'hwe_' . $entity . '_seo_' . $field;
}

/** Acción del nonce de una entidad. */
function hwe_seo_nonce_action( string $entity ): string {
	return 'hwe_' . $entity . '_seo_save';
}

/** Nombre del campo del nonce de una entidad. */
function hwe_seo_nonce_name( string $entity ): string {
	return 'hwe_' . $entity . '_seo_nonce';
}

/**
 * HTML de los dos campos con su contador en vivo.
 *
 * @param array{title:string,description:string} $values Valores actuales.
 * @param string $wrap 'box' (caja lateral de la ficha), 'term-add' o 'term-edit'.
 * @param array{title:string,description:string} $help Ayuda bajo cada campo.
 */
function hwe_seo_render_fields( string $entity, array $values, string $wrap, array $help ): string {
	$labels = array(
		'title'       => __( 'Título SEO', 'hwe' ),
		'description' => __( 'Descripción SEO', 'hwe' ),
	);

	$out = wp_nonce_field( hwe_seo_nonce_action( $entity ), hwe_seo_nonce_name( $entity ), true, false );
	foreach ( hwe_seo_fields() as $field => [ $meta, $max, $min, $textarea ] ) {
		$name  = hwe_seo_input_name( $entity, $field );
		$value = (string) ( $values[ $field ] ?? '' );
		$input = $textarea
			? '<textarea id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" maxlength="' . (int) $max . '" rows="3" style="width:100%" data-hwe-seo-min="' . (int) $min . '">' . esc_textarea( $value ) . '</textarea>'
			: '<input type="text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" maxlength="' . (int) $max . '" style="width:100%" data-hwe-seo-min="' . (int) $min . '" value="' . esc_attr( $value ) . '">';
		$count = '<span id="' . esc_attr( $name ) . '_count" aria-live="polite" style="font-size:12px"></span>';
		$label = '<label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $labels[ $field ] ) . '</strong></label>';
		$desc  = esc_html( (string) ( $help[ $field ] ?? '' ) );

		if ( 'term-edit' === $wrap ) {
			$out .= '<tr class="form-field"><th>' . $label . '</th><td>' . $input . $count . '<p class="description">' . $desc . '</p></td></tr>';
		} elseif ( 'term-add' === $wrap ) {
			$out .= '<div class="form-field">' . $label . $input . $count . '<p>' . $desc . '</p></div>';
		} else {
			$out .= '<p>' . $label . '<br>' . $input . $count . '</p><p class="description">' . $desc . '</p>';
		}
	}

	return $out . hwe_seo_counter_script();
}

/**
 * Contador en vivo (una vez por página): gris sin texto, ámbar si es corto, verde en
 * rango, rojo si se pasa. Engancha cualquier campo con `data-hwe-seo-min`.
 */
function hwe_seo_counter_script(): string {
	static $printed = false;
	if ( $printed ) {
		return '';
	}
	$printed = true;
	return '<script>(function(){function wire(f){var c=document.getElementById(f.id+"_count");if(!c)return;var min=+f.dataset.hweSeoMin,max=+f.maxLength;function u(){var n=f.value.length;c.textContent=n===0?"":n+" / "+max;c.style.color=n>max?"#b32d2e":(n<min?"#996800":"#1e7e34");}f.addEventListener("input",u);u();}function init(){document.querySelectorAll("[data-hwe-seo-min]").forEach(wire);}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}})();</script>';
}

/**
 * Lee del POST los campos de una entidad (comprobando su nonce) y devuelve los valores
 * saneados y recortados, o `null` si el nonce no es válido. La capacidad la comprueba
 * quien llama.
 *
 * @return array<string,string>|null meta => valor ('' = borrar).
 */
function hwe_seo_read_post( string $entity ): ?array {
	$nonce_name = hwe_seo_nonce_name( $entity );
	if ( ! isset( $_POST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), hwe_seo_nonce_action( $entity ) ) ) {
		return null;
	}
	$values = array();
	foreach ( hwe_seo_fields() as $field => [ $meta, $max, $min, $textarea ] ) {
		$name = hwe_seo_input_name( $entity, $field );
		$raw  = isset( $_POST[ $name ] ) ? (string) wp_unslash( $_POST[ $name ] ) : '';
		$clean = $textarea ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		$values[ $meta ] = mb_substr( $clean, 0, $max );
	}
	return $values;
}

/**
 * Guarda los campos SEO de un post (entrada, página, producto) desde su caja.
 */
function hwe_seo_save_post_fields( string $entity, int $post_id ): void {
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$values = hwe_seo_read_post( $entity );
	if ( null === $values ) {
		return;
	}
	foreach ( $values as $meta => $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta );
		} else {
			// `update_post_meta` hace `wp_unslash`: sin `wp_slash` una `\` se pierde al guardar.
			update_post_meta( $post_id, $meta, wp_slash( $value ) );
		}
	}
}

/**
 * Guarda los campos SEO de un término.
 */
function hwe_seo_save_term_fields( string $entity, int $term_id, string $capability ): void {
	if ( ! current_user_can( $capability ) ) {
		return;
	}
	$values = hwe_seo_read_post( $entity );
	if ( null === $values ) {
		return;
	}
	foreach ( $values as $meta => $value ) {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $meta );
		} else {
			update_term_meta( $term_id, $meta, wp_slash( $value ) );
		}
	}
}

/** Valores actuales de un post o término. @return array{title:string,description:string} */
function hwe_seo_current_values( string $kind, int $id ): array {
	$get = 'term' === $kind ? 'get_term_meta' : 'get_post_meta';
	return array(
		'title'       => (string) $get( $id, '_hwe_seo_title', true ),
		'description' => (string) $get( $id, '_hwe_seo_description', true ),
	);
}

/**
 * Registra `seoTitle` y `seoDescription` en un tipo de WPGraphQL. Null = sin override.
 *
 * @param string $kind 'post' (lee meta de post) o 'term' (meta de término).
 * @param array{seoTitle:string,seoDescription:string} $descriptions
 */
function hwe_seo_register_graphql_fields( string $graphql_type, string $kind, array $descriptions ): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}

	$resolver = static function ( string $meta_key ) use ( $kind ): callable {
		return static function ( $node ) use ( $meta_key, $kind ): ?string {
			$id = 0;
			if ( is_object( $node ) ) {
				$keys = 'term' === $kind ? array( 'term_id', 'databaseId' ) : array( 'databaseId', 'ID' );
				foreach ( $keys as $key ) {
					if ( isset( $node->$key ) && (int) $node->$key > 0 ) {
						$id = (int) $node->$key;
						break;
					}
				}
			}
			if ( ! $id ) {
				return null;
			}
			$value = 'term' === $kind ? (string) get_term_meta( $id, $meta_key, true ) : (string) get_post_meta( $id, $meta_key, true );
			return '' !== $value ? $value : null;
		};
	};

	$metas = array( 'seoTitle' => '_hwe_seo_title', 'seoDescription' => '_hwe_seo_description' );
	foreach ( $metas as $name => $meta ) {
		register_graphql_field( $graphql_type, $name, array(
			'type'        => 'String',
			'description' => $descriptions[ $name ] ?? '',
			'resolve'     => $resolver( $meta ),
		) );
	}
}

/**
 * Columna «SEO» en el listado de un tipo de contenido: marca qué fichas tienen título
 * y descripción propios.
 */
function hwe_seo_register_list_column( string $post_type ): void {
	add_filter( "manage_{$post_type}_posts_columns", static function ( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key || 'name' === $key ) {
				$out['hwe_seo'] = __( 'SEO', 'hwe' );
			}
		}
		return $out;
	} );

	add_action( "manage_{$post_type}_posts_custom_column", static function ( string $column, int $post_id ): void {
		if ( 'hwe_seo' !== $column ) {
			return;
		}
		$values = hwe_seo_current_values( 'post', $post_id );
		$mark   = static fn ( bool $ok ): string => $ok
			? '<span style="color:#1e7e34" aria-hidden="true">✓</span><span class="screen-reader-text">' . esc_html__( 'sí', 'hwe' ) . '</span>'
			: '<span style="color:#996800" aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'falta', 'hwe' ) . '</span>';
		echo esc_html__( 'Título', 'hwe' ) . ' ' . $mark( '' !== $values['title'] ) . '<br>' . esc_html__( 'Descripción', 'hwe' ) . ' ' . $mark( '' !== $values['description'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- $mark escapa.
	}, 10, 2 );
}
