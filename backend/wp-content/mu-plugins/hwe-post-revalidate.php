<?php
/**
 * Plugin Name: HWE Post Revalidate
 * Description: Dispara la revalidación ISR del tag `posts` en el frontend
 *              Next.js al guardar una entrada de blog (CPT `post`) — mismo
 *              mecanismo que usa WooCommerce para `products`
 *              (webhook nativo → Revalidation::trigger(), reutilizado aquí
 *              con el tag `posts`). Sin esto, una entrada nueva o editada
 *              solo se refleja en /blog, /sitemap.xml, etc. tras la ventana
 *              ISR, no al instante como ocurre con productos.
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Revalida el tag `posts` una vez (función nombrada para poder probarla). */
function hwe_post_revalidate_now(): void {
	if ( ! class_exists( 'HWE\ControlCenter\Revalidation' ) ) {
		return;
	}
	\HWE\ControlCenter\Revalidation::trigger( 'posts' );
}

add_action( 'save_post_post', 'hwe_post_revalidate_now' );

// Renombrar una etiqueta (Entradas → Etiquetas) cambia los chips de todas sus
// entradas, que viven bajo el mismo tag de caché `posts`: sin esto, el cambio
// tardaba la ventana ISR entera en verse.
add_action( 'edited_post_tag', 'hwe_post_revalidate_now' );

// Las categorías del blog tienen página propia: su nombre, su descripción y
// cuáles existen salen de la misma caché `posts`. Crear, editar o borrar una
// categoría las cambia.
foreach ( [ 'created_category', 'edited_category', 'delete_category' ] as $hwe_category_hook ) {
	add_action( $hwe_category_hook, 'hwe_post_revalidate_now' );
}
