<?php
/**
 * CSS de marca: sobrescribe woocommerce/templates/emails/email-styles.php.
 *
 * WC_Email::style_inline() incluye este archivo y Emogrifier aplica las reglas en línea
 * sobre el HTML final, incluido lo que sigue generando WooCommerce (la tabla de productos
 * y direcciones: .email-order-details, .address, .td).
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hwe_email_color' ) ) {
	include WC()->plugin_path() . '/templates/emails/email-styles.php';
	return;
}

$hwe_foreground = hwe_email_color( 'foreground' );
$hwe_border     = '#e5e7eb';
?>
body, #outer_wrapper {
	background-color: <?php echo esc_attr( hwe_email_color( 'background' ) ); ?>;
	margin: 0;
	padding: 0;
}

h1, h2, h3 {
	font-family: <?php echo esc_attr( hwe_email_font_stack( 'heading' ) ); ?>;
	color: <?php echo esc_attr( $hwe_foreground ); ?>;
	font-weight: 600;
}

body, p, td, th, .email-order-details, .address {
	font-family: <?php echo esc_attr( hwe_email_font_stack( 'body' ) ); ?>;
	color: <?php echo esc_attr( $hwe_foreground ); ?>;
	font-size: 14.5px;
	line-height: 1.65;
}

a, .link {
	color: <?php echo esc_attr( hwe_email_color( 'brand' ) ); ?>;
}

.email-order-details th {
	text-align: left;
	font-size: 11px;
	letter-spacing: .06em;
	text-transform: uppercase;
	color: #6b7280;
	border-bottom: 1px solid <?php echo esc_attr( $hwe_border ); ?>;
	padding: 0 0 8px;
}

/* WooCommerce emite <table class="td" border="1">: sin esto los clientes de correo pintan
   el bisel 3-D por defecto. */
.td {
	border: 0;
	vertical-align: middle;
}

.email-order-details td, .email-order-details th {
	padding: 12px 0;
	border-bottom: 1px solid <?php echo esc_attr( $hwe_border ); ?>;
}

.order-totals-total td, .order-totals-total th {
	font-weight: 700;
	font-size: 15px;
	border-top: 1px solid <?php echo esc_attr( $hwe_border ); ?>;
	padding-top: 12px;
}

.address {
	padding: 12px 0;
	border: 0;
}

blockquote {
	margin: 0 0 20px;
	padding: 14px 18px;
	background: <?php echo esc_attr( hwe_email_tint( hwe_email_color( 'brand_light' ), 0.15 ) ); ?>;
	border-radius: 8px;
	color: <?php echo esc_attr( $hwe_foreground ); ?>;
}

<?php
// Media query de móvil: Emogrifier no puede ponerla en línea, la deja en un <style>.
echo hwe_email_responsive_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
