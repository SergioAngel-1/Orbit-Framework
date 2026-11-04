<?php
/**
 * Plugin Name: HWE Admin Branding
 * Description: Viste el panel con la identidad de HWE Orbit: esquema de color propio
 *              (por defecto para todos los usuarios) y pantalla de login con la marca de la
 *              instancia (o la de Orbit si no tiene una).
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 *
 * - Registra el esquema «HWE Orbit» (`hwe-admin-branding/colors.css`, compilado con el
 *   `_admin.scss` del core desde `colors.scss`) y lo aplica a quien tenga el esquema por
 *   defecto de WordPress (`fresh`) o ninguno. Un usuario que elige otro esquema lo conserva.
 * - Login: con la marca de la instancia del Control Center (`brand.name`/`brand.logo` y
 *   `design.colors`, con los valores del esquema de reserva) si la tiene, y Orbit solo como un
 *   «Con HWE Orbit» pequeño al pie. Cada texto y el anillo de foco se comprueban contra su fondo
 *   (WCAG AA) y caen a tinta o blanco si no llegan. Sin logo y con el nombre de serie, el login
 *   de Orbit: campo cadmio, su símbolo en lugar del logo de WordPress y botón en tinta.
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

/**
 * Valor guardado en el Control Center (`brand.*`, `design.colors.*`), sin el valor por
 * defecto del esquema. Vacío si no hay Control Center o el campo no está guardado.
 *
 * @param list<string> $path
 */
function hwe_admin_branding_saved( array $path ): string {
	if ( ! class_exists( 'HWE\\ControlCenter\\Storage' ) ) {
		return '';
	}
	$value = HWE\ControlCenter\Storage::get( $path );
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * Valor por defecto del esquema del Control Center (memoizado por petición).
 *
 * @param list<string> $path
 */
function hwe_admin_branding_schema_default( array $path ): string {
	static $defaults = null;
	if ( null === $defaults ) {
		$defaults = class_exists( 'HWE\\ControlCenter\\Walkers\\DefaultsWalker' ) && class_exists( 'HWE\\ControlCenter\\Schema' )
			? ( new HWE\ControlCenter\Walkers\DefaultsWalker() )->walk( HWE\ControlCenter\Schema::core() )
			: array();
	}
	$current = $defaults;
	foreach ( $path as $key ) {
		if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
			return '';
		}
		$current = $current[ $key ];
	}
	return is_scalar( $current ) ? (string) $current : '';
}

/** `#rrggbb` válido o null (el valor acaba dentro de una hoja de estilos). */
function hwe_admin_branding_hex( string $value ): ?string {
	return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? strtolower( $value ) : null;
}

/** Color de diseño (`design.colors.<nombre>`): el guardado, el del esquema o `$fallback`. */
function hwe_admin_branding_color( string $name, string $fallback ): string {
	return hwe_admin_branding_hex( hwe_admin_branding_saved( array( 'design', 'colors', $name ) ) )
		?? hwe_admin_branding_hex( hwe_admin_branding_schema_default( array( 'design', 'colors', $name ) ) )
		?? $fallback;
}

/** Luminancia relativa WCAG 2.x de un `#rrggbb`. */
function hwe_admin_branding_luminance( string $hex ): float {
	$channels = array();
	foreach ( str_split( substr( $hex, 1 ), 2 ) as $pair ) {
		$c          = hexdec( $pair ) / 255;
		$channels[] = $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
	}
	return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/** Contraste WCAG entre dos `#rrggbb` (1 a 21). */
function hwe_admin_branding_contrast( string $a, string $b ): float {
	$la = hwe_admin_branding_luminance( $a );
	$lb = hwe_admin_branding_luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

/**
 * `$preferred` si llega a `$min` de contraste sobre `$background`; si no, el que más
 * contraste dé de tinta (`#111111`) y blanco.
 */
function hwe_admin_branding_readable( string $preferred, string $background, float $min = 4.5 ): string {
	if ( hwe_admin_branding_contrast( $preferred, $background ) >= $min ) {
		return $preferred;
	}
	return hwe_admin_branding_contrast( '#111111', $background ) >= hwe_admin_branding_contrast( '#ffffff', $background )
		? '#111111'
		: '#ffffff';
}

/** URL absoluta del logo (`brand.logo`); la medioteca guarda rutas relativas a uploads. */
function hwe_admin_branding_logo_url(): string {
	$url = hwe_admin_branding_saved( array( 'brand', 'logo' ) );
	if ( '' === $url ) {
		return '';
	}
	$pos = strpos( $url, 'wp-content/uploads/' );
	if ( false !== $pos ) {
		return home_url( '/' . substr( $url, $pos ) );
	}
	return $url;
}

/**
 * Marca de la instancia para el login, o null si no tiene una propia: sin logo y con el
 * nombre vacío o el de serie del esquema, el login sigue con la marca de Orbit.
 *
 * @return array{name:string,logo:string}|null
 */
function hwe_admin_branding_instance_brand(): ?array {
	$name    = hwe_admin_branding_saved( array( 'brand', 'name' ) );
	if ( $name === hwe_admin_branding_schema_default( array( 'brand', 'name' ) ) ) {
		$name = '';
	}
	$logo = hwe_admin_branding_logo_url();
	if ( '' === $name && '' === $logo ) {
		return null;
	}
	return array(
		'name' => '' !== $name ? $name : (string) get_bloginfo( 'name' ),
		'logo' => $logo,
	);
}

/** CSS de la pantalla de login con la marca de Orbit. */
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

/**
 * CSS del login con la marca de la instancia: fondo `surface`, formulario en `background`,
 * botón en `brand` y anillo de foco en `brand` (o en `secondary`, o en el texto, el primero
 * que llegue a 3:1 sobre el formulario). Cada texto se comprueba contra su fondo (AA, 4,5:1)
 * y cae a tinta o blanco si no llega.
 *
 * @param array{name:string,logo:string} $brand
 */
function hwe_admin_branding_instance_login_css( array $brand ): string {
	$page   = hwe_admin_branding_color( 'surface', '#e9e9e6' );
	$card   = hwe_admin_branding_color( 'background', '#ffffff' );
	$text   = hwe_admin_branding_readable( hwe_admin_branding_color( 'foreground', '#0a0a0a' ), $card );
	$link   = hwe_admin_branding_readable( hwe_admin_branding_color( 'foreground', '#0a0a0a' ), $page );
	$button = hwe_admin_branding_color( 'brand', '#111111' );
	$hover  = hwe_admin_branding_color( 'brand_dark', $button );
	$label  = hwe_admin_branding_readable( '#ffffff', $button );
	$hlabel = hwe_admin_branding_readable( '#ffffff', $hover );
	$ring   = $text;
	foreach ( array( $button, hwe_admin_branding_color( 'secondary', '#2a3fd6' ) ) as $candidate ) {
		if ( hwe_admin_branding_contrast( $candidate, $card ) >= 3 && hwe_admin_branding_contrast( $candidate, $page ) >= 3 ) {
			$ring = $candidate;
			break;
		}
	}
	$credit = esc_url( hwe_admin_branding_asset_url( 'orbit-symbol.svg' ) );

	if ( '' !== $brand['logo'] ) {
		$logo = esc_url( $brand['logo'] );
		$head = <<<CSS
.login h1 a {
	background-image: url("{$logo}");
	background-size: contain;
	background-position: center;
	width: 100%;
	max-width: 240px;
	height: 84px;
}
CSS;
	} else {
		$head = <<<CSS
.login h1 a {
	background: none;
	width: auto;
	height: auto;
	text-indent: 0;
	overflow: visible;
	font-size: 28px;
	font-weight: 700;
	line-height: 1.2;
	color: {$link};
	overflow-wrap: anywhere;
}
CSS;
	}

	return <<<CSS
body.login { background: {$page}; color: {$text}; }
{$head}
.login form { background: {$card}; color: {$text}; border: 0; border-top: 4px solid {$button}; border-radius: 0; box-shadow: none; }
.login form label, .login form .forgetmenot label { color: {$text}; }
.login #nav a, .login #backtoblog a, .login .privacy-policy-link, .login h1 a:hover { color: {$link}; }
.login .language-switcher label .dashicons { color: {$link}; }
.login #nav a:hover, .login #backtoblog a:hover { text-decoration: underline; }
.wp-core-ui .button-primary {
	background: {$button};
	border-color: {$button};
	border-radius: 0;
	color: {$label};
}
.wp-core-ui .button-primary:hover,
.wp-core-ui .button-primary:focus { background: {$hover}; border-color: {$hover}; color: {$hlabel}; }
.login a:focus, .login a:focus-visible,
.wp-core-ui .button:focus, .wp-core-ui .button-primary:focus,
.login input:focus, .login input[type="checkbox"]:focus {
	outline: 2px solid {$ring};
	outline-offset: 2px;
	box-shadow: none;
}
.login input[type="text"]:focus,
.login input[type="password"]:focus,
.login input[type="email"]:focus { border-color: {$ring}; }
.login .button.wp-hide-pw .dashicons { color: {$text}; }
.login .message, .login .notice, .login .success { border-left-color: {$button}; }
.hwe-login-credit {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	margin: 24px auto 16px;
	font-size: 12px;
	color: {$link};
}
.hwe-login-credit__mark {
	width: 14px;
	height: 14px;
	background-color: currentColor;
	-webkit-mask: url("{$credit}") center / contain no-repeat;
	mask: url("{$credit}") center / contain no-repeat;
}
CSS;
}

function hwe_admin_branding_login_enqueue(): void {
	if ( ! hwe_admin_branding_enabled() ) {
		return;
	}
	$brand = hwe_admin_branding_instance_brand();
	wp_add_inline_style( 'login', null !== $brand ? hwe_admin_branding_instance_login_css( $brand ) : hwe_admin_branding_login_css() );
}
add_action( 'login_enqueue_scripts', 'hwe_admin_branding_login_enqueue' );

/** Con la marca de la instancia, Orbit queda como una referencia pequeña al pie del login. */
function hwe_admin_branding_login_credit(): void {
	if ( ! hwe_admin_branding_enabled() || null === hwe_admin_branding_instance_brand() ) {
		return;
	}
	echo '<p class="hwe-login-credit"><span class="hwe-login-credit__mark" aria-hidden="true"></span>'
		. esc_html__( 'Con HWE Orbit', 'hwe' ) . '</p>';
}
add_action( 'login_footer', 'hwe_admin_branding_login_credit' );

/** El logo del login lleva al sitio y se anuncia con su nombre, no con WordPress.org. */
function hwe_admin_branding_login_header_url( $url ) {
	return hwe_admin_branding_enabled() ? home_url( '/' ) : $url;
}
add_filter( 'login_headerurl', 'hwe_admin_branding_login_header_url' );

/** wp-login.php imprime este texto sin escapar. */
function hwe_admin_branding_login_header_text( $text ) {
	if ( ! hwe_admin_branding_enabled() ) {
		return $text;
	}
	$brand = hwe_admin_branding_instance_brand();
	return null !== $brand ? esc_html( $brand['name'] ) : get_bloginfo( 'name' );
}
add_filter( 'login_headertext', 'hwe_admin_branding_login_header_text' );
