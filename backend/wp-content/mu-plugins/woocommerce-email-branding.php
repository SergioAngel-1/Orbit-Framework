<?php
/**
 * Plugin Name: WooCommerce Email Branding
 * Description: Personaliza las plantillas de email transaccional de WooCommerce
 *              con la marca del cliente (nombre, colores, logo). Se integra con
 *              el HWE Control Center para leer la configuración de marca.
 *
 *              Los estilos se inyectan como CSS inline en los correos HTML
 *              respetando el diseño headless. Si no hay configuración guardada,
 *              usa valores por defecto sensibles.
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * 1) CABECERA Y PIE DE EMAIL PERSONALIZADOS
 *    Envuelve el contenido del email con la marca del cliente.
 * ------------------------------------------------------------------------ */

// Cabecera: reemplaza el header por defecto de WooCommerce.
add_action(
	'woocommerce_email_header',
	'hwe_email_header',
	1,
	2
);

function hwe_email_header( string $email_heading, string $email = '' ): void {
	$brand_name = hwe_get_brand_name();
	$logo_url   = hwe_get_brand_logo();
	$logo_html  = $logo_url
		? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $brand_name ) . '" style="max-height:60px;width:auto;margin-bottom:16px;">'
		: '';

	echo '<div style="background:#f8f9fa;padding:32px 0;font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">';
	echo '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">';
	echo '<div style="padding:32px 32px 16px;border-bottom:1px solid #e5e7eb;text-align:center;">';
	echo $logo_html;
	echo '<h1 style="margin:0;font-size:20px;font-weight:600;color:#111827;">' . esc_html( $email_heading ) . '</h1>';
	echo '</div>';
	echo '<div style="padding:24px 32px;color:#374151;font-size:14px;line-height:1.6;">';
}

// Pie: reemplaza el footer por defecto de WooCommerce.
add_action(
	'woocommerce_email_footer',
	'hwe_email_footer',
	1
);

function hwe_email_footer(): void {
	$brand_name = hwe_get_brand_name();
	$company    = hwe_get_legal_company();
	$address    = hwe_get_legal_address();
	$email_addr = hwe_get_contact_email();

	echo '</div>'; // cierra content
	echo '<div style="padding:16px 32px;background:#f3f4f6;text-align:center;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;">';

	if ( $company ) {
		echo esc_html( $company ) . '<br>';
	}
	if ( $address ) {
		echo esc_html( $address ) . '<br>';
	}
	echo esc_html( $brand_name ) . '</div>';
	echo '</div>'; // cierra card
	echo '</div>'; // cierra wrapper
}

/* ---------------------------------------------------------------------------
 * 2) COLORES DE MARCA EN EMAILS
 *    Personaliza los colores usados por WooCommerce en los emails.
 * ------------------------------------------------------------------------ */
add_filter(
	'woocommerce_email_styles',
	'hwe_email_styles'
);

function hwe_email_styles( string $css ): string {
	$brand_color = hwe_get_brand_color();
	$bg_color    = '#f8f9fa';
	$text_color  = '#111827';

	return <<<CSS
.background-color{background-color:{$bg_color};}
.base_color{background-color:{$brand_color};}
.text_color{color:{$text_color};}
.main-color{border-color:{$brand_color};}
h1,h2,h3,h4,h5,h6{color:{$text_color};font-weight:600;}
a{color:{$brand_color};}
.wc-button{
	display:inline-block;
	padding:12px 24px;
	background-color:{$brand_color};
	color:#ffffff !important;
	text-decoration:none;
	border-radius:6px;
	font-size:14px;
	font-weight:600;
}
{$css}
CSS;
}

/* ---------------------------------------------------------------------------
 * 3) LECTURA DE CONFIGURACIÓN DESDE HWE CONTROL CENTER
 *    Fallback a valores por defecto si el plugin no está activo.
 * ------------------------------------------------------------------------ */

function hwe_get_brand_name(): string {
	if ( function_exists( 'HWE\\ControlCenter\\Storage::get' ) ) {
		$name = HWE\ControlCenter\Storage::get( [ 'brand', 'name' ] );
		if ( $name ) {
			return $name;
		}
	}
	return get_bloginfo( 'name' ) ?: 'HeadlessWP';
}

function hwe_get_brand_logo(): string {
	if ( function_exists( 'HWE\\ControlCenter\\Storage::get' ) ) {
		return (string) HWE\ControlCenter\Storage::get( [ 'brand', 'logo_url' ] );
	}
	return '';
}

function hwe_get_brand_color(): string {
	if ( function_exists( 'HWE\\ControlCenter\\Storage::get' ) ) {
		$color = HWE\ControlCenter\Storage::get( [ 'design', 'colors', 'brand' ] );
		if ( $color ) {
			return $color;
		}
	}
	return '#2563eb';
}

function hwe_get_legal_company(): string {
	if ( function_exists( 'HWE\\ControlCenter\\Storage::get' ) ) {
		return (string) HWE\ControlCenter\Storage::get( [ 'legal', 'company' ] );
	}
	return '';
}

function hwe_get_legal_address(): string {
	if ( function_exists( 'HWE\\ControlCenter\\Storage::get' ) ) {
		return (string) HWE\ControlCenter\Storage::get( [ 'legal', 'address' ] );
	}
	return '';
}

function hwe_get_contact_email(): string {
	if ( function_exists( 'HWE\\ControlCenter\\Storage::get' ) ) {
		return (string) HWE\ControlCenter\Storage::get( [ 'legal', 'email' ] );
	}
	return get_option( 'admin_email', '' );
}
