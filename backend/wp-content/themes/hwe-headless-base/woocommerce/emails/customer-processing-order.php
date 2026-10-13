<?php
/**
 * Pedido recibido y en preparación: sobrescribe woocommerce/templates/emails/customer-processing-order.php.
 *
 * Solo dice qué correo es. El marco, el texto por idioma y los enlaces al frontend viven en
 * mu-plugins/hwe-email-chrome.php (`hwe_email_render_order_email`). Sin ese mu-plugin se
 * usa la plantilla original de WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hwe_email_render_order_email' ) ) {
	include WC()->plugin_path() . '/templates/emails/customer-processing-order.php';
	return;
}

hwe_email_render_order_email( 'customer_processing_order', get_defined_vars() );
