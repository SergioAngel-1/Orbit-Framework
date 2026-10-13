<?php
/**
 * Piezas del marco de marca: cabecera, pie, insignia, botón, encabezado y CSS adaptable a móvil.
 *
 * Parte de `hwe-email-chrome.php`, que lo carga: las funciones son globales y se reparten por
 * tema para que cada archivo se lea de una vez.
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Piezas del marco
// ---------------------------------------------------------------------------

/**
 * Aclara un color hex mezclándolo con blanco (fondo de una insignia).
 * `$amount` 0 = blanco, 1 = el color original.
 */
function hwe_email_tint( string $hex, float $amount ): string {
	$hex = ltrim( $hex, '#' );
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return '#f3f4f6';
	}
	$mix = static fn ( int $c ): int => (int) round( $c + ( 255 - $c ) * ( 1 - $amount ) );
	return sprintf( '#%02x%02x%02x', $mix( (int) hexdec( substr( $hex, 0, 2 ) ) ), $mix( (int) hexdec( substr( $hex, 2, 2 ) ) ), $mix( (int) hexdec( substr( $hex, 4, 2 ) ) ) );
}

/** Insignia del tipo de correo. `$color` = nombre de un color de diseño. */
function hwe_email_badge( string $label, string $color = 'brand' ): string {
	$bg = hwe_email_tint( hwe_email_color( $color ), 0.12 );
	// Texto en brand_dark sobre cualquier fondo aclarado: los tonos de marca crudos no
	// llegan al contraste AA sobre su propio tinte; el oscuro sí.
	return sprintf(
		'<span style="display:inline-block;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:6px 12px;border-radius:999px;background:%s;color:%s;margin-bottom:18px;">%s</span>',
		esc_attr( $bg ),
		esc_attr( hwe_email_color( 'brand_dark' ) ),
		esc_html( $label )
	);
}

function hwe_email_heading( string $text ): string {
	return sprintf(
		'<h1 style="font-family:%s;font-size:22px;line-height:1.3;margin:0 0 10px;color:%s;">%s</h1>',
		esc_attr( hwe_email_font_stack( 'heading' ) ),
		esc_attr( hwe_email_color( 'foreground' ) ),
		esc_html( $text )
	);
}

function hwe_email_button( string $label, string $url ): string {
	return sprintf(
		'<p style="text-align:center;margin:26px 0 22px;"><a href="%s" style="display:inline-block;background:%s;color:#ffffff;font-weight:700;font-size:14px;padding:13px 30px;border-radius:8px;text-decoration:none;font-family:%s;">%s</a></p>',
		esc_url( $url ),
		esc_attr( hwe_email_color( 'brand' ) ),
		esc_attr( hwe_email_font_stack( 'body' ) ),
		esc_html( $label )
	);
}

/**
 * Reglas de móvil del marco: la caja es `width:100%` + `max-width:560px` (con un ancho
 * fijo, en un móvil el correo se salía de la pantalla) y en pantallas estrechas se
 * recortan los márgenes. Solo @media: lo demás va inline.
 */
function hwe_email_responsive_css(): string {
	return '@media only screen and (max-width: 600px) {'
		. '.hwe-email-outer { padding: 16px 8px !important; }'
		. '.hwe-email-logo { padding: 24px 20px 18px !important; }'
		. '.hwe-email-body { padding: 26px 20px 6px !important; }'
		. '.hwe-email-footer { padding: 22px 20px 26px !important; }'
		. '}';
}

/** Apertura del documento y de la tarjeta, hasta el inicio del cuerpo. */
function hwe_email_open( string $title, string $lang ): string {
	$brand_name = hwe_email_brand_name();
	$logo_url   = hwe_email_logo_url();
	$background = hwe_email_color( 'background' );
	$font_urls  = array_filter( array( hwe_email_setting( array( 'design', 'typography', 'font_url' ) ), hwe_email_setting( array( 'design', 'typography', 'font_heading_url' ) ) ) );

	$logo_html = '' !== $logo_url
		? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $brand_name ) . '" width="110" style="display:block;margin:0 auto;height:auto;">'
		: '<p style="margin:0;font-family:' . esc_attr( hwe_email_font_stack( 'heading' ) ) . ';font-size:19px;font-weight:600;color:' . esc_attr( hwe_email_color( 'brand_dark' ) ) . ';">' . esc_html( $brand_name ) . '</p>';

	$imports = '';
	foreach ( $font_urls as $font_url ) {
		$imports .= "@import url('" . esc_url_raw( $font_url ) . "');";
	}

	return '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head>'
		. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
		. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
		. '<title>' . esc_html( $title ) . '</title>'
		. ( '' !== $imports ? '<style>' . $imports . '</style>' : '' )
		. '<style>' . hwe_email_responsive_css() . '</style>'
		. '</head><body style="margin:0;padding:0;background:' . esc_attr( $background ) . ';">'
		. '<table role="presentation" class="hwe-email-outer" width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $background ) . ';padding:36px 20px;"><tr><td align="center">'
		. '<!--[if mso]><table role="presentation" width="560" align="center" cellpadding="0" cellspacing="0"><tr><td><![endif]-->'
		. '<table role="presentation" class="hwe-email-card" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background:' . esc_attr( hwe_email_color( 'surface' ) ) . ';border-radius:10px;overflow:hidden;">'
		. '<tr><td align="center" class="hwe-email-logo" style="background:' . esc_attr( hwe_email_tint( hwe_email_color( 'brand_light' ), 0.25 ) ) . ';padding:30px 32px 22px;">' . $logo_html . '</td></tr>'
		. '<tr><td style="height:3px;background:linear-gradient(90deg,' . esc_attr( hwe_email_color( 'brand' ) ) . ',' . esc_attr( hwe_email_color( 'secondary' ) ) . ' 60%,' . esc_attr( hwe_email_color( 'accent' ) ) . ');line-height:3px;font-size:0;">&nbsp;</td></tr>'
		. '<tr><td class="hwe-email-body" style="padding:34px 32px 8px;font-family:' . esc_attr( hwe_email_font_stack( 'body' ) ) . ';color:' . esc_attr( hwe_email_color( 'foreground' ) ) . ';">';
}

/** Cierre del cuerpo, pie de marca y cierre del documento. */
function hwe_email_close( ?string $locale = null ): string {
	$muted   = 'color:#6b7280;';
	$company = hwe_email_legal( 'company' );
	$address = hwe_email_legal( 'address' );
	$contact = hwe_email_legal( 'email' );
	if ( '' === $contact ) {
		$contact = (string) get_option( 'admin_email', '' );
	}

	$footer = '<p style="margin:0 0 4px;font-size:12px;font-weight:600;color:' . esc_attr( hwe_email_color( 'brand_dark' ) ) . ';">' . esc_html( hwe_email_brand_name() ) . '</p>';
	if ( '' !== $company ) {
		$footer .= '<p style="margin:0 0 4px;font-size:11.5px;' . $muted . '">' . esc_html( $company ) . '</p>';
	}
	if ( '' !== $address ) {
		$footer .= '<p style="margin:0 0 4px;font-size:11.5px;' . $muted . '">' . esc_html( $address ) . '</p>';
	}
	if ( '' !== $contact ) {
		$footer .= '<p style="margin:0;font-size:11.5px;' . $muted . '">' . esc_html( hwe_email_copy( 'footer_contact', $locale, $contact ) ) . '</p>';
	}

	return '</td></tr>'
		. '<tr><td align="center" class="hwe-email-footer" style="background:' . esc_attr( hwe_email_color( 'background' ) ) . ';padding:26px 32px 30px;font-family:' . esc_attr( hwe_email_font_stack( 'body' ) ) . ';">' . $footer . '</td></tr>'
		. '</table><!--[if mso]></td></tr></table><![endif]--></td></tr></table></body></html>';
}

/**
 * Documento completo (cabecera + cuerpo + pie) para los correos propios de hwe-auth.php,
 * que no pasan por WC_Emails. `$body_html` ya llega redactado y escapado en `$locale`.
 */
function hwe_render_auth_email( string $heading, string $body_html, string $locale = '' ): string {
	$locale = hwe_email_resolve_locale( $locale );
	return hwe_email_open( hwe_email_brand_name(), $locale )
		. hwe_email_heading( $heading )
		. $body_html
		. hwe_email_close( $locale );
}
