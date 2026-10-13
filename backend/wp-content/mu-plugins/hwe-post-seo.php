<?php
/**
 * Plugin Name: HWE Post SEO
 * Description: Título y descripción SEO por entrada de blog, override opcional
 *              del título/excerpt nativos para <title> y meta description. Se
 *              edita en la ficha de CUALQUIER entrada y se expone por
 *              WPGraphQL como `Post.seoTitle` / `Post.seoDescription`. No
 *              depende de RankMath/Yoast — es la fuente propia de SEO por post.
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Largos que Google enseña sin cortar: el `<title>` hacia los 60 caracteres,
 * la meta description hacia los 155. Los mínimos solo pintan el contador en
 * ámbar; no obligan a nada.
 */
const HWE_POST_SEO_TITLE_MIN = 50;
const HWE_POST_SEO_TITLE_MAX = 60;
const HWE_POST_SEO_DESC_MIN  = 140;
const HWE_POST_SEO_DESC_MAX  = 155;

/**
 * Meta box con título y descripción SEO, en la barra lateral (contexto
 * `side`, junto a Categorías/Autor) en vez de `normal` — ahí queda oculto
 * bajo el panel "Meta Boxes" colapsado, debajo de todo el contenido del
 * editor de bloques, y nadie lo encuentra sin scrollear a propósito.
 */
add_action( 'add_meta_boxes_post', function ( WP_Post $post ): void {
	add_meta_box( 'hwe_post_seo', __( 'SEO de la entrada', 'hwe' ), 'hwe_post_seo_render_box', 'post', 'side', 'default' );
} );

function hwe_post_seo_render_box( WP_Post $post ): void {
	wp_nonce_field( 'hwe_post_seo_save', 'hwe_post_seo_nonce' );
	$title = (string) get_post_meta( $post->ID, '_hwe_seo_title', true );
	$desc  = (string) get_post_meta( $post->ID, '_hwe_seo_description', true );
	echo '<p><label for="hwe_seo_title"><strong>' . esc_html__( 'Título SEO', 'hwe' ) . '</strong></label><br>';
	echo '<input type="text" id="hwe_seo_title" name="hwe_seo_title" maxlength="' . esc_attr( (string) HWE_POST_SEO_TITLE_MAX ) . '" style="width:100%" value="' . esc_attr( $title ) . '">';
	echo '<span id="hwe_seo_title_count" aria-live="polite" style="font-size:12px"></span></p>';
	echo '<p class="description">' . esc_html__( 'Ideal: 50–60 caracteres. El sitio añade la marca al final solo si el total cabe en 60; si no cabe, el título sale tal cual lo escribes.', 'hwe' ) . '</p>';
	echo '<p><label for="hwe_seo_description"><strong>' . esc_html__( 'Descripción SEO', 'hwe' ) . '</strong></label><br>';
	echo '<textarea id="hwe_seo_description" name="hwe_seo_description" maxlength="' . esc_attr( (string) HWE_POST_SEO_DESC_MAX ) . '" rows="3" style="width:100%">' . esc_textarea( $desc ) . '</textarea>';
	echo '<span id="hwe_seo_description_count" aria-live="polite" style="font-size:12px"></span></p>';
	echo '<p class="description">' . esc_html__( 'Ideal: 140–155 caracteres. Opcional: si se deja vacío, el sitio público usa el título y el comienzo del texto de la entrada (cortado con puntos suspensivos).', 'hwe' ) . '</p>';
	// Contador en vivo: gris sin texto, ámbar si es corto, verde en rango, rojo si se pasa.
	echo '<script>(function(){function wire(id,min,max){var f=document.getElementById(id),c=document.getElementById(id+"_count");if(!f||!c)return;function u(){var n=f.value.length;c.textContent=n===0?"":n+" / "+max;c.style.color=n>max?"#b32d2e":(n<min?"#996800":"#1e7e34");}f.addEventListener("input",u);u();}wire("hwe_seo_title",' . (int) HWE_POST_SEO_TITLE_MIN . ',' . (int) HWE_POST_SEO_TITLE_MAX . ');wire("hwe_seo_description",' . (int) HWE_POST_SEO_DESC_MIN . ',' . (int) HWE_POST_SEO_DESC_MAX . ');})();</script>';
}

/** Guarda el SEO de la entrada (función nombrada para poder probarla). */
function hwe_post_seo_save( int $post_id ): void {
	if ( ! isset( $_POST['hwe_post_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hwe_post_seo_nonce'] ) ), 'hwe_post_seo_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$title = isset( $_POST['hwe_seo_title'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['hwe_seo_title'] ) ), 0, HWE_POST_SEO_TITLE_MAX ) : '';
	$desc  = isset( $_POST['hwe_seo_description'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['hwe_seo_description'] ) ), 0, HWE_POST_SEO_DESC_MAX ) : '';

	if ( $title === '' ) {
		delete_post_meta( $post_id, '_hwe_seo_title' );
	} else {
		update_post_meta( $post_id, '_hwe_seo_title', $title );
	}
	if ( $desc === '' ) {
		delete_post_meta( $post_id, '_hwe_seo_description' );
	} else {
		update_post_meta( $post_id, '_hwe_seo_description', $desc );
	}
}
add_action( 'save_post_post', 'hwe_post_seo_save' );

/*
 * Columna «SEO» en Entradas: cuáles tienen título y descripción propios y cuáles
 * salen con el automático. Es la única forma de ver de un vistazo qué falta sin
 * abrirlas una a una. Solo lectura; se edita en la caja «SEO de la
 * entrada». Va después del título.
 */
add_filter( 'manage_post_posts_columns', function ( array $columns ): array {
	$out = [];
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( $key === 'title' ) {
			$out['hwe_seo'] = __( 'SEO', 'hwe' );
		}
	}
	return $out;
} );

add_action( 'manage_post_posts_custom_column', function ( string $column, int $post_id ): void {
	if ( $column !== 'hwe_seo' ) {
		return;
	}
	$has_title = (string) get_post_meta( $post_id, '_hwe_seo_title', true ) !== '';
	$has_desc  = (string) get_post_meta( $post_id, '_hwe_seo_description', true ) !== '';
	$mark      = static fn ( bool $ok ): string => $ok
		? '<span style="color:#1e7e34" aria-hidden="true">✓</span><span class="screen-reader-text">' . esc_html__( 'sí', 'hwe' ) . '</span>'
		: '<span style="color:#996800" aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'falta', 'hwe' ) . '</span>';
	echo esc_html__( 'Título', 'hwe' ) . ' ' . $mark( $has_title ) . '<br>' . esc_html__( 'Descripción', 'hwe' ) . ' ' . $mark( $has_desc ); // phpcs:ignore WordPress.Security.EscapeOutput -- $mark escapa.
}, 10, 2 );

/** Expone el SEO en WPGraphQL como `Post.seoTitle` / `Post.seoDescription`. */
function hwe_post_seo_register_graphql(): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}

	$resolve_meta = static function ( string $meta_key ): callable {
		return function ( $post ) use ( $meta_key ): ?string {
			$id = 0;
			if ( is_object( $post ) ) {
				if ( isset( $post->databaseId ) ) {
					$id = (int) $post->databaseId;
				} elseif ( isset( $post->ID ) ) {
					$id = (int) $post->ID;
				}
			}
			$value = $id ? (string) get_post_meta( $id, $meta_key, true ) : '';
			return $value !== '' ? $value : null;
		};
	};

	register_graphql_field( 'Post', 'seoTitle', [
		'type'        => 'String',
		'description' => __( 'Título SEO override (<title> / og:title). Si es null, el frontend usa el título de la entrada.', 'hwe' ),
		'resolve'     => $resolve_meta( '_hwe_seo_title' ),
	] );
	register_graphql_field( 'Post', 'seoDescription', [
		'type'        => 'String',
		'description' => __( 'Descripción SEO override (meta description / og:description). Si es null, el frontend usa el resumen de la entrada.', 'hwe' ),
		'resolve'     => $resolve_meta( '_hwe_seo_description' ),
	] );
}
add_action( 'graphql_register_types', 'hwe_post_seo_register_graphql' );
