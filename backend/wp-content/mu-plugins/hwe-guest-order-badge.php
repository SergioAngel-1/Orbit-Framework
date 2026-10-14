<?php
/**
 * Plugin Name: HWE Guest Order Badge
 * Description: Marca «Invitado» en los pedidos sin cuenta (listado y ficha del
 *              pedido en wp-admin).
 * Author:      Sergio Jauregui
 * Version:     2.0.0
 *
 * Con «Exigir inicio de sesión para comprar» apagado (HWE Control Center →
 * Tienda), un pedido puede entrar SIN cuenta: `customer_id = 0`. WooCommerce
 * no lo liga a nadie —tampoco a una cuenta posterior con el mismo correo; no
 * se agrupan a propósito—, así que ese pedido no aparece en «Mis pedidos» de
 * ningún cliente y la única vía de contacto es el correo/teléfono de
 * facturación. La marca lo hace visible de un vistazo en el listado y en la
 * ficha, para que nadie lo busque en una cuenta que no existe.
 *
 * El criterio es el dato (`customer_id === 0`), no cómo se creó el pedido: un
 * pedido importado de otro sistema sin cliente también es de invitado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_GUEST_ORDER_COLUMN = 'hwe_customer_type';

function hwe_is_guest_order( $order ): bool {
	return $order instanceof WC_Order && 0 === (int) $order->get_customer_id();
}

function hwe_guest_order_badge( WC_Order $order ): string {
	$email = $order->get_billing_email();
	$title = $email !== ''
		? sprintf( 'Pedido sin cuenta: la confirmación y el seguimiento van a %s.', $email )
		: 'Pedido sin cuenta.';
	return sprintf(
		'<mark class="hwe-guest-badge" title="%s">%s</mark>',
		esc_attr( $title ),
		esc_html__( 'Invitado', 'hwe' )
	);
}

/** Inserta la columna justo después de «Pedido». */
function hwe_guest_order_add_column( array $columns ): array {
	$result = array();
	foreach ( $columns as $key => $label ) {
		$result[ $key ] = $label;
		if ( 'order_number' === $key ) {
			$result[ HWE_GUEST_ORDER_COLUMN ] = esc_html__( 'Cliente', 'hwe' );
		}
	}
	if ( ! isset( $result[ HWE_GUEST_ORDER_COLUMN ] ) ) {
		$result[ HWE_GUEST_ORDER_COLUMN ] = esc_html__( 'Cliente', 'hwe' );
	}
	return $result;
}

/** Con cuenta no se pinta nada: la marca solo tiene sentido como excepción. */
function hwe_guest_order_render_cell( $order ): void {
	if ( hwe_is_guest_order( $order ) ) {
		echo hwe_guest_order_badge( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en hwe_guest_order_badge().
	}
}

// Listado HPOS (WooCommerce → Pedidos con `wc_orders`), el modo por defecto de Woo.
add_filter( 'woocommerce_shop_order_list_table_columns', 'hwe_guest_order_add_column' );
add_action(
	'woocommerce_shop_order_list_table_custom_column',
	static function ( $column, $order ) {
		if ( HWE_GUEST_ORDER_COLUMN === $column ) {
			hwe_guest_order_render_cell( $order );
		}
	},
	10,
	2
);

// Listado clásico (pedidos como posts), por si una instalación no usa HPOS.
add_filter( 'manage_edit-shop_order_columns', 'hwe_guest_order_add_column' );
add_action(
	'manage_shop_order_posts_custom_column',
	static function ( $column, $post_id ) {
		if ( HWE_GUEST_ORDER_COLUMN === $column ) {
			hwe_guest_order_render_cell( wc_get_order( $post_id ) );
		}
	},
	10,
	2
);

// Ficha del pedido: bajo el bloque «General» (fecha, estado, cliente).
add_action(
	'woocommerce_admin_order_data_after_order_details',
	static function ( $order ) {
		if ( ! hwe_is_guest_order( $order ) ) {
			return;
		}
		printf(
			'<p class="form-field form-field-wide hwe-guest-badge-field">%s <span class="description">%s</span></p>',
			hwe_guest_order_badge( $order ), // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en hwe_guest_order_badge().
			esc_html__( 'Comprado sin cuenta. No aparece en «Mis pedidos» de ningún cliente.', 'hwe' )
		);
	}
);

add_action(
	'admin_head',
	static function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ), true ) ) {
			return;
		}
		?>
		<style>
			.hwe-guest-badge {
				display: inline-flex;
				align-items: center;
				padding: 0 .75em;
				line-height: 2em;
				border-radius: 4px;
				background: #f0e6ef;
				color: #5b3752;
				font-weight: 600;
				white-space: nowrap;
				cursor: help;
			}
			.column-<?php echo esc_attr( HWE_GUEST_ORDER_COLUMN ); ?> { width: 7em; }
			.hwe-guest-badge-field .description { display: block; margin-top: .4em; }
		</style>
		<?php
	}
);
