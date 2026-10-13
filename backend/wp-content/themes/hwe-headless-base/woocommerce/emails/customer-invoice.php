<?php
/**
 * Detalle del pedido / enlace de pago: sobrescribe woocommerce/templates/emails/customer-invoice.php.
 *
 * Solo dice qué correo es. El marco, el texto por idioma y los enlaces al frontend viven en
 * mu-plugins/hwe-email-chrome.php (`hwe_email_render_order_email`). Sin ese mu-plugin se
 * usa la plantilla original de WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hwe_email_render_order_email' ) ) {
	include WC()->plugin_path() . '/templates/emails/customer-invoice.php';
	return;
}

hwe_email_render_order_email( 'customer_invoice', get_defined_vars() );
