<?php
/**
 * Plugin Name: HWE Featured Post
 * Description: Submenú "Post Destacado" bajo Entradas — permite fijar a mano
 *              qué post se muestra como destacado en /blog (en vez del más
 *              reciente, que es el comportamiento por defecto). Guarda un
 *              único ID en la opción `hwe_featured_post_id` (vacío = usa el
 *              más reciente) y lo expone en WPGraphQL como
 *              `RootQuery.hweFeaturedPost` (tipo `Post`, null si no hay
 *              selección o el post ya no existe/no está publicado).
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_FEATURED_POST_OPTION = 'hwe_featured_post_id';
const HWE_FEATURED_POST_PAGE_SLUG = 'hwe-featured-post';

add_action( 'admin_menu', function (): void {
	add_submenu_page(
		'edit.php',
		__( 'Post Destacado', 'hwe' ),
		__( 'Post Destacado', 'hwe' ),
		'edit_posts',
		HWE_FEATURED_POST_PAGE_SLUG,
		'hwe_featured_post_render_page'
	);
} );

function hwe_featured_post_render_page(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	if ( isset( $_POST['hwe_featured_post_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hwe_featured_post_nonce'] ) ), 'hwe_featured_post_save' ) ) {
		$selected = isset( $_POST['hwe_featured_post_id'] ) ? absint( $_POST['hwe_featured_post_id'] ) : 0;
		if ( $selected > 0 ) {
			update_option( HWE_FEATURED_POST_OPTION, $selected );
		} else {
			delete_option( HWE_FEATURED_POST_OPTION );
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Guardado.', 'hwe' ) . '</p></div>';
	}

	$current = (int) get_option( HWE_FEATURED_POST_OPTION, 0 );

	// Solo publicadas: una privada no es visible en el sitio público, así que
	// no tiene sentido poder fijarla como destacada.
	$posts = get_posts( [
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'numberposts'    => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );

	echo '<div class="wrap"><h1>' . esc_html__( 'Post Destacado', 'hwe' ) . '</h1>';
	echo '<p>' . esc_html__( 'Elige qué entrada se muestra como destacada en /blog. Por defecto (sin seleccionar nada) se muestra siempre la entrada más reciente.', 'hwe' ) . '</p>';
	echo '<form method="post">';
	wp_nonce_field( 'hwe_featured_post_save', 'hwe_featured_post_nonce' );
	echo '<select name="hwe_featured_post_id" style="min-width:420px">';
	echo '<option value="0">' . esc_html__( '— Último post (por defecto) —', 'hwe' ) . '</option>';
	foreach ( $posts as $post ) {
		printf(
			'<option value="%d" %s>%s — %s</option>',
			(int) $post->ID,
			selected( $current, $post->ID, false ),
			esc_html( get_the_date( 'Y-m-d', $post ) ),
			esc_html( $post->post_title )
		);
	}
	echo '</select> ';
	submit_button( __( 'Guardar', 'hwe' ), 'primary', 'submit', false );
	echo '</form></div>';
}

/** Expone la selección en WPGraphQL como `RootQuery.hweFeaturedPost`. */
function hwe_featured_post_register_graphql(): void {
	if ( ! function_exists( 'register_graphql_field' ) ) {
		return;
	}

	register_graphql_field( 'RootQuery', 'hweFeaturedPost', [
		'type'        => 'Post',
		'description' => __( 'Post fijado a mano como destacado en /blog (Entradas → Post Destacado). Null si no hay selección, o si el post ya no existe/no está publicado — el frontend debe usar el más reciente en ese caso.', 'hwe' ),
		'resolve'     => function ( $root, array $args, \WPGraphQL\AppContext $context ) {
			$post_id = (int) get_option( HWE_FEATURED_POST_OPTION, 0 );
			if ( ! $post_id ) {
				return null;
			}
			$post = get_post( $post_id );
			// Solo publicadas: si se fijó un post y luego pasó a private
			// (p. ej. por falta de imagen destacada), deja de contar como
			// destacado — el frontend cae al más reciente.
			if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
				return null;
			}
			return $context->get_loader( 'post' )->load_deferred( $post_id );
		},
	] );
}
add_action( 'graphql_register_types', 'hwe_featured_post_register_graphql' );
