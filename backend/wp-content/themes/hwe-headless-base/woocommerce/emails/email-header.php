<?php
/**
 * Cabecera de marca de los correos: sobrescribe woocommerce/templates/emails/email-header.php.
 *
 * Recibe `$email_heading` y `$store_name` (WC_Emails::email_header()). Los cuerpos de los
 * correos al cliente ponen su propio H1 con insignia y lo avisan con
 * `$GLOBALS['hwe_email_heading_handled']`; el resto (avisos a la tienda) recibe aquí su H1.
 * El marco vive en mu-plugins/hwe-email-chrome.php.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hwe_email_open' ) ) {
	include WC()->plugin_path() . '/templates/emails/email-header.php';
	return;
}

echo hwe_email_open( (string) ( $store_name ?? get_bloginfo( 'name' ) ), (string) get_bloginfo( 'language' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
if ( empty( $GLOBALS['hwe_email_heading_handled'] ) ) {
	echo hwe_email_heading( (string) ( $email_heading ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
unset( $GLOBALS['hwe_email_heading_handled'] );
