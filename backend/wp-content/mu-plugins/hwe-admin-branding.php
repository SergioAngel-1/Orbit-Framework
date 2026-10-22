<?php
/**
 * Plugin Name: HWE Admin Branding
 * Description: Viste el panel con la identidad de HWE Orbit: esquema de color propio
 *              (por defecto para todos los usuarios) y pantalla de login con la marca.
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 *
 * - Registra el esquema «HWE Orbit» (`hwe-admin-branding/colors.css`, compilado con el
 *   `_admin.scss` del core desde `colors.scss`) y lo aplica a quien tenga el esquema por
 *   defecto de WordPress (`fresh`) o ninguno. Un usuario que elige otro esquema lo conserva.
 * - Login: campo cadmio, el símbolo de Orbit en lugar del logo de WordPress y botón en tinta.
 *
 * Una instancia que quiera su propia marca en el panel lo apaga con
 * `add_filter( 'hwe_admin_branding', '__return_false' );` desde `mu-plugins/instance/`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HWE_ADMIN_BRANDING_SCHEME = 'hwe-orbit';

/** ¿Está activa la marca del framework en el panel? Filtro `hwe_admin_branding`. */
function hwe_admin_branding_enabled(): bool {
	return (bool) apply_filters( 'hwe_admin_branding', true );
}

/** URL pública de un recurso de `mu-plugins/hwe-admin-branding/`. */
function hwe_admin_branding_asset_url( string $file ): string {
	return content_url( 'mu-plugins/hwe-admin-branding/' . $file );
}

/** Registra el esquema en Perfil → Esquema de color del panel. */
function hwe_admin_branding_register_scheme(): void {
	if ( ! hwe_admin_branding_enabled() ) {
		return;
	}
	wp_admin_css_color(
		HWE_ADMIN_BRANDING_SCHEME,
		'HWE Orbit',
		hwe_admin_branding_asset_url( 'colors.css' ),
		[ '#111111', '#f2c200', '#2a3fd6', '#e9e9e6' ],
		[
			'base'    => '#e9e9e6',
			'focus'   => '#111111',
			'current' => '#111111',
		]
	);
}
add_action( 'admin_init', 'hwe_admin_branding_register_scheme' );

/**
 * El esquema de Orbit sustituye al de WordPress por defecto, no a una elección propia.
 *
 * @param mixed $color Valor guardado en `admin_color` (vacío o `fresh` si nadie lo cambió).
 * @return mixed
 */
function hwe_admin_branding_default_scheme( $color ) {
	if ( ! hwe_admin_branding_enabled() ) {
		return $color;
	}
	return ( '' === (string) $color || 'fresh' === $color ) ? HWE_ADMIN_BRANDING_SCHEME : $color;
}
add_filter( 'get_user_option_admin_color', 'hwe_admin_branding_default_scheme' );

/** CSS de la pantalla de login. */
function hwe_admin_branding_login_css(): string {
	$logo = esc_url( hwe_admin_branding_asset_url( 'orbit-symbol.svg' ) );
	return <<<CSS
body.login { background: #f2c200; }
.login h1 a {
	background-image: url("{$logo}");
	background-size: 84px 84px;
	width: 84px;
	height: 84px;
}
.login form { border: 0; border-radius: 0; box-shadow: none; }
.login #nav a, .login #backtoblog a, .login .privacy-policy-link { color: #111111; }
.login #nav a:hover, .login #backtoblog a:hover { color: #000000; }
.wp-core-ui .button-primary {
	background: #111111;
	border-color: #111111;
	border-radius: 0;
	color: #ffffff;
}
.wp-core-ui .button-primary:hover,
.wp-core-ui .button-primary:focus { background: #000000; border-color: #000000; color: #ffffff; }
.wp-core-ui .button-primary:focus { box-shadow: 0 0 0 1px #ffffff, 0 0 0 3px #2a3fd6; }
.login input[type="text"]:focus,
.login input[type="password"]:focus,
.login input[type="email"]:focus { border-color: #2a3fd6; box-shadow: 0 0 0 1px #2a3fd6; }
.login .button.wp-hide-pw .dashicons { color: #111111; }
.login .message, .login .notice, .login .success { border-left-color: #2a3fd6; }
CSS;
}

function hwe_admin_branding_login_enqueue(): void {
	if ( ! hwe_admin_branding_enabled() ) {
		return;
	}
	wp_add_inline_style( 'login', hwe_admin_branding_login_css() );
}
add_action( 'login_enqueue_scripts', 'hwe_admin_branding_login_enqueue' );

/** El logo del login lleva al sitio y se anuncia con su nombre, no con WordPress.org. */
function hwe_admin_branding_login_header_url( $url ) {
	return hwe_admin_branding_enabled() ? home_url( '/' ) : $url;
}
add_filter( 'login_headerurl', 'hwe_admin_branding_login_header_url' );

function hwe_admin_branding_login_header_text( $text ) {
	return hwe_admin_branding_enabled() ? get_bloginfo( 'name' ) : $text;
}
add_filter( 'login_headertext', 'hwe_admin_branding_login_header_text' );
