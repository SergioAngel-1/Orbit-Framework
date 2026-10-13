<?php
/**
 * Nota en el pedido: sobrescribe woocommerce/templates/emails/customer-note.php.
 *
 * Solo dice qué correo es. El marco, el texto por idioma y los enlaces al frontend viven en
 * mu-plugins/hwe-email-chrome.php (`hwe_email_render_order_email`). Sin ese mu-plugin se
 * usa la plantilla original de WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hwe_email_render_order_email' ) ) {
	include WC()->plugin_path() . '/templates/emails/customer-note.php';
	return;
}

hwe_email_render_order_email( 'customer_note', get_defined_vars() );
