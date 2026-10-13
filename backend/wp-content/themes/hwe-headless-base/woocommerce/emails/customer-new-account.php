<?php
/**
 * Bienvenida (cuenta nueva): sobrescribe woocommerce/templates/emails/customer-new-account.php.
 *
 * Solo dice qué correo es. El marco, el texto por idioma y los enlaces al frontend viven en
 * mu-plugins/hwe-email-chrome.php (`hwe_email_render_account_email`). Sin ese mu-plugin se
 * usa la plantilla original de WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hwe_email_render_account_email' ) ) {
	include WC()->plugin_path() . '/templates/emails/customer-new-account.php';
	return;
}

hwe_email_render_account_email( 'customer_new_account', get_defined_vars() );
