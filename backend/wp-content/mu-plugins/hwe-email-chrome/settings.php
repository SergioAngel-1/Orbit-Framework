<?php
/**
 * Ajustes del Control Center que usan los correos: colores, tipografías, nombre, logo y datos legales.
 *
 * Parte de `hwe-email-chrome.php`, que lo carga: las funciones son globales y se reparten por
 * tema para que cada archivo se lea de una vez.
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Ajustes del Control Center
// ---------------------------------------------------------------------------

/**
 * Valor de un campo del Control Center: el guardado o, si no hay, el valor por defecto
 * del esquema. Sin el Control Center, `$fallback`.
 *
 * @param list<string> $path
 */
function hwe_email_setting( array $path, string $fallback = '' ): string {
	if ( ! class_exists( 'HWE\\ControlCenter\\Storage' ) ) {
		return $fallback;
	}
	$value = HWE\ControlCenter\Storage::get( $path );
	if ( is_scalar( $value ) && '' !== (string) $value ) {
		return (string) $value;
	}
	$default = hwe_email_schema_default( $path );
	return '' !== $default ? $default : $fallback;
}

/** Valor por defecto del esquema para un campo (memoizado por petición). */
function hwe_email_schema_default( array $path ): string {
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

/**
 * Color de diseño (`design.colors.<nombre>`) como `#rrggbb`. Un valor que no sea un hex
 * válido (va dentro de un atributo `style`) cae al del esquema.
 */
function hwe_email_color( string $name ): string {
	$value = hwe_email_setting( array( 'design', 'colors', $name ) );
	if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
		return $value;
	}
	$default = hwe_email_schema_default( array( 'design', 'colors', $name ) );
	return preg_match( '/^#[0-9a-fA-F]{6}$/', $default ) ? $default : '#000000';
}

/**
 * Pila tipográfica para el correo: la fuente del Control Center (solo letras, cifras,
 * espacios y guiones: va dentro de un `style`) y reservas seguras. Casi ningún cliente de
 * correo carga la fuente de la marca, así que la reserva es lo que suele verse.
 *
 * Sin `font_heading`, los titulares usan la pila del cuerpo, como dice el panel. Con ella, su
 * reserva es serif salvo que la instancia elija otra con `hwe_email_heading_font_fallback`.
 *
 * @param 'body'|'heading' $which
 */
function hwe_email_font_stack( string $which ): string {
	$field = 'heading' === $which ? 'font_heading' : 'font_sans';
	$name  = trim( (string) preg_replace( '/[^A-Za-z0-9 \-]/', '', hwe_email_setting( array( 'design', 'typography', $field ) ) ) );
	if ( 'heading' === $which && '' === $name ) {
		return hwe_email_font_stack( 'body' );
	}
	$tail = 'heading' === $which
		? hwe_email_heading_font_fallback()
		: "'Segoe UI', 'Helvetica Neue', Arial, sans-serif";
	return '' !== $name ? "'" . $name . "', " . $tail : $tail;
}

/**
 * Reserva de una fuente de titulares con nombre. Filtro `hwe_email_heading_font_fallback`: una
 * marca con titulares sans-serif devuelve aquí una pila sans-serif. El valor acaba dentro de un
 * `style`: solo se aceptan letras, cifras, espacios, guiones, comas y comillas simples; otro
 * valor, o uno vacío, se registra y deja la reserva por defecto.
 */
function hwe_email_heading_font_fallback(): string {
	$default  = "Georgia, 'Times New Roman', serif";
	$filtered = apply_filters( 'hwe_email_heading_font_fallback', $default );
	if ( is_string( $filtered ) && preg_match( "/^[A-Za-z0-9 ,'\-]+$/", $filtered ) ) {
		return $filtered;
	}
	error_log( '[hwe] hwe_email_heading_font_fallback: valor no válido; se usa la reserva por defecto' );
	return $default;
}

function hwe_email_brand_name(): string {
	return hwe_email_setting( array( 'brand', 'name' ), (string) get_bloginfo( 'name' ) );
}

/** Logo del correo (`brand.email_logo`, si no `brand.logo`) como URL absoluta. */
function hwe_email_logo_url(): string {
	$url = hwe_email_setting( array( 'brand', 'email_logo' ) );
	if ( '' === $url ) {
		$url = hwe_email_setting( array( 'brand', 'logo' ) );
	}
	if ( '' === $url ) {
		return '';
	}
	// La medioteca guarda rutas relativas a uploads (`/wp-content/uploads/…`): un cliente
	// de correo necesita la URL completa.
	$pos = strpos( $url, 'wp-content/uploads/' );
	if ( false !== $pos ) {
		return home_url( '/' . substr( $url, $pos ) );
	}
	return $url;
}

function hwe_email_legal( string $field ): string {
	return hwe_email_setting( array( 'legal', $field ) );
}
