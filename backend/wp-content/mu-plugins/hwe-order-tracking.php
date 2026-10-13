<?php
/**
 * Plugin Name: HWE — Transportadora y guía del pedido
 * Description: Tres campos en la pantalla del pedido (transportadora, número
 *              de guía y enlace de seguimiento) guardados como meta del
 *              pedido con claves fijas, para que una integración externa
 *              (ERP, logística) los lea por `metaData` de WooGraphQL o WC REST.
 * Author:      Headless Web Ecosystem
 * Version:     2.0.0
 *
 * Las claves son un CONTRATO con quien las lee: `_carrier`, `_tracking_number`,
 * `_tracking_url`. El prefijo `_` las oculta de «Campos personalizados» de
 * wp-admin; solo se editan desde esta caja. `_carrier` es texto libre a
 * propósito: el nombre de la transportadora tal cual lo escribe la tienda.
 *
 * Funciona con HPOS y con el almacenamiento clásico de pedidos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_ORDER_TRACKING_NONCE = 'hwe_order_tracking';

/** clave de meta => [ etiqueta, tipo de campo ] */
function hwe_order_tracking_fields(): array {
	return [
		'_carrier'         => [ __( 'Transportadora', 'hwe' ), 'text' ],
		'_tracking_number' => [ __( 'Número de guía', 'hwe' ), 'text' ],
		'_tracking_url'    => [ __( 'Enlace de seguimiento', 'hwe' ), 'url' ],
	];
}

add_action(
	'add_meta_boxes',
	static function (): void {
		if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
			return;
		}
		add_meta_box(
			'hwe-order-tracking',
			__( 'Envío: transportadora y guía', 'hwe' ),
			'hwe_order_tracking_render',
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}
);

/**
 * @param WP_Post|WC_Order $post_or_order HPOS pasa el pedido; el modo clásico, el post.
 */
function hwe_order_tracking_render( $post_or_order ): void {
	$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
	if ( ! $order ) {
		return;
	}
	wp_nonce_field( HWE_ORDER_TRACKING_NONCE, HWE_ORDER_TRACKING_NONCE . '_nonce' );
	foreach ( hwe_order_tracking_fields() as $key => [ $label, $type ] ) {
		printf(
			'<p><label for="hwe%1$s"><strong>%2$s</strong></label><br>'
			. '<input type="%3$s" id="hwe%1$s" name="hwe%1$s" value="%4$s" class="widefat" maxlength="255"></p>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) $order->get_meta( $key ) )
		);
	}
}

/**
 * Se guarda con el pedido, antes de que WooCommerce lo persista, para que un
 * solo «Actualizar» escriba estado y guía juntos.
 *
 * @param int           $order_id
 * @param WC_Order|null $order
 */
function hwe_order_tracking_save( $order_id, $order = null ): void {
	if ( ! isset( $_POST[ HWE_ORDER_TRACKING_NONCE . '_nonce' ] )
		|| ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST[ HWE_ORDER_TRACKING_NONCE . '_nonce' ] ) ),
			HWE_ORDER_TRACKING_NONCE
		)
		|| ! current_user_can( 'edit_shop_order', $order_id )
	) {
		return;
	}
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	foreach ( hwe_order_tracking_fields() as $key => [ , $type ] ) {
		$raw = isset( $_POST[ 'hwe' . $key ] ) ? wp_unslash( $_POST[ 'hwe' . $key ] ) : '';
		// El enlace solo admite http(s): se lee desde fuera y acaba en un
		// `<a>`; `esc_url_raw` descarta `javascript:` y similares.
		$value = 'url' === $type
			? esc_url_raw( trim( $raw ), [ 'http', 'https' ] )
			: sanitize_text_field( $raw );
		if ( '' === $value ) {
			$order->delete_meta_data( $key );
		} else {
			$order->update_meta_data( $key, $value );
		}
	}
	$order->save_meta_data();
}
add_action( 'woocommerce_process_shop_order_meta', 'hwe_order_tracking_save', 10, 2 );
