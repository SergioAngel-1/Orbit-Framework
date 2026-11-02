<?php
/**
 * Plugin Name: HWE — Esquema de la medioteca en las peticiones del BFF
 * Description: Las URL de los adjuntos que WordPress entrega al BFF llevan el esquema con el
 *              que está configurado el sitio (`siteurl` / `home`), no el que declara la
 *              petición en `X-Forwarded-Proto`.
 * Author:      Sergio Jauregui
 * Version:     1.0.0
 *
 * POR QUÉ
 * -------
 * WooCommerce solo acepta las credenciales ck/cs cuando `is_ssl()` es true, y el BFF habla con
 * WordPress por HTTP en la red interna: `wcFetch` (`frontend/src/lib/woocommerce/client.ts`)
 * manda `X-Forwarded-Proto: https` para que el `wp-config` trate la petición como HTTPS. La
 * misma cabecera hace que `wp_get_attachment_url` (y el `srcset`) pasen las URL de la
 * medioteca a `https://` aunque el sitio se sirva por `http://` (en local). El frontend solo
 * admite en `next/image` el origen de `WORDPRESS_PUBLIC_URL`, así que todo lo que lee por
 * wc/v3 (`/api/store/products?include=`: búsqueda, colecciones, recientes, lista de deseos)
 * pintaba imágenes rotas.
 *
 * ACOTADO
 * -------
 * Solo actúa en una petición del BFF (secreto interno válido), fuera del panel, servida como
 * HTTPS, y sobre una URL `https://` cuyo host y puerto son los de `siteurl` o `home` cuando
 * estos están declarados `http://`. En producción el sitio ya es `https`: no cambia nada. Un
 * navegador en un panel tras TLS con `home` en `http` (staging mal configurado) tampoco se ve
 * afectado: no presenta el secreto, y bajarle la medioteca a `http` le daría contenido mixto.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/hwe-bff-secret.php';

/**
 * La URL con esquema `http` si es `https` y su host y puerto son los de un origen del sitio
 * declarado `http`. Cualquier otra URL (otro host, otro puerto, relativa) vuelve tal cual.
 *
 * @param list<string> $site_urls Valores de `siteurl` y `home`.
 */
function hwe_media_scheme_restore( string $url, array $site_urls ): string {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' || ! isset( $parts['host'] ) ) {
		return $url;
	}

	foreach ( $site_urls as $site_url ) {
		$site = parse_url( (string) $site_url );
		if ( ! is_array( $site ) || strtolower( (string) ( $site['scheme'] ?? '' ) ) !== 'http' || ! isset( $site['host'] ) ) {
			continue;
		}
		if ( strcasecmp( $site['host'], $parts['host'] ) === 0 && ( $site['port'] ?? null ) === ( $parts['port'] ?? null ) ) {
			return 'http' . substr( $url, strlen( 'https' ) );
		}
	}

	return $url;
}

/** ¿Esta petición es del BFF y WordPress la cree HTTPS? Solo entonces se corrige el esquema. */
function hwe_media_scheme_applies(): bool {
	static $from_bff = null;
	if ( is_admin() || ! is_ssl() ) {
		return false;
	}
	if ( null === $from_bff ) {
		$from_bff = hwe_bff_only_has_valid_secret();
	}
	return $from_bff;
}

/**
 * Filtro de una URL de la medioteca.
 *
 * @param mixed $url
 * @return mixed
 */
function hwe_media_scheme_filter_url( $url ) {
	if ( ! is_string( $url ) || ! hwe_media_scheme_applies() ) {
		return $url;
	}
	return hwe_media_scheme_restore( $url, array( (string) get_option( 'siteurl' ), (string) get_option( 'home' ) ) );
}

add_filter( 'wp_get_attachment_url', 'hwe_media_scheme_filter_url', 99 );
add_filter( 'woocommerce_placeholder_img_src', 'hwe_media_scheme_filter_url', 99 );

// `wp_calculate_image_srcset` sube a https la base del srcset por su cuenta (con `is_ssl()`).
add_filter(
	'wp_calculate_image_srcset',
	static function ( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $key => $source ) {
			if ( is_array( $source ) && isset( $source['url'] ) ) {
				$sources[ $key ]['url'] = hwe_media_scheme_filter_url( $source['url'] );
			}
		}
		return $sources;
	},
	99
);
