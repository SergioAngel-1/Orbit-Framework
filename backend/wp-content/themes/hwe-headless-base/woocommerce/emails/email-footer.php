<?php
/**
 * Pie de marca de los correos: sobrescribe woocommerce/templates/emails/email-footer.php.
 * El marco vive en mu-plugins/hwe-email-chrome.php.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hwe_email_close' ) ) {
	include WC()->plugin_path() . '/templates/emails/email-footer.php';
	return;
}

echo hwe_email_close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
