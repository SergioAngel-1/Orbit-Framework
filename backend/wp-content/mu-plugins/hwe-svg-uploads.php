<?php
/**
 * Plugin Name: HWE SVG Uploads
 * Description: Permite subir SVG a la Biblioteca de medios de forma segura:
 *              MIME allowlist + corrección de filetype + sanitización en
 *              prefilter (sin scripts, handlers ni entidades externas).
 *              Solo usuarios con `upload_files`.
 *
 * Author:      Headless Web Ecosystem
 * Version:     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ¿Puede este usuario subir SVG? Capacidad de medios; no abrimos
 * ALLOW_UNFILTERED_UPLOADS.
 */
function hwe_svg_user_can_upload(): bool {
	return current_user_can( 'upload_files' );
}

function hwe_svg_is_svg_filename( string $filename ): bool {
	return (bool) preg_match( '/\.svgz?$/i', $filename );
}

/**
 * MIME allowlist (solo si el usuario puede subir ficheros).
 */
add_filter( 'upload_mimes', function ( array $mimes ): array {
	if ( hwe_svg_user_can_upload() ) {
		$mimes['svg']  = 'image/svg+xml';
		$mimes['svgz'] = 'image/svg+xml';
	}
	return $mimes;
} );

/**
 * WordPress a menudo deja ext/type vacíos en SVG (finfo). Forzamos el tipo.
 *
 * Importante: WP puede llamar este filtro con `$mimes === null` (p. ej. desde
 * `_wp_handle_upload`). Tipar `array` provoca TypeError → HTTP 500 y el
 * mensaje genérico de “Suggested maximum size is 2560 pixels” en Plupload.
 */
add_filter( 'wp_check_filetype_and_ext', function ( array $data, string $file, string $filename, $mimes = null ): array {
	if ( ! hwe_svg_user_can_upload() || ! hwe_svg_is_svg_filename( $filename ) ) {
		return $data;
	}
	$mime_map = is_array( $mimes ) ? $mimes : null;
	$filetype = wp_check_filetype( $filename, $mime_map );
	if ( empty( $data['ext'] ) || empty( $data['type'] ) || $data['type'] !== 'image/svg+xml' ) {
		$data['ext']  = $filetype['ext'] ?: 'svg';
		$data['type'] = 'image/svg+xml';
	}
	$data['proper_filename'] = $data['proper_filename'] ?? false;
	return $data;
}, 10, 4 );

/**
 * Sanitiza el SVG en disco (tmp) antes de moverlo a uploads.
 * Rechaza el upload si no se puede limpiar o queda vacío.
 *
 * @param array{name?:string,type?:string,tmp_name?:string,error?:int|string,size?:int} $file
 * @return array{name?:string,type?:string,tmp_name?:string,error?:int|string,size?:int}
 */
function hwe_svg_sanitize_upload( array $file ): array {
	$name = (string) ( $file['name'] ?? '' );
	$tmp  = (string) ( $file['tmp_name'] ?? '' );
	if ( $tmp === '' || ! hwe_svg_is_svg_filename( $name ) ) {
		return $file;
	}
	if ( ! hwe_svg_user_can_upload() ) {
		$file['error'] = __( 'No tienes permiso para subir archivos SVG.', 'hwe' );
		return $file;
	}
	if ( ! is_readable( $tmp ) ) {
		$file['error'] = __( 'No se pudo leer el SVG subido.', 'hwe' );
		return $file;
	}

	$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_string( $raw ) || $raw === '' ) {
		$file['error'] = __( 'El SVG está vacío o es ilegible.', 'hwe' );
		return $file;
	}

	// Rechazo rápido de vectores clásicos antes del DOM.
	if ( preg_match( '/<!ENTITY/i', $raw )
		|| preg_match( '/<\?xml-stylesheet/i', $raw )
		|| preg_match( '/\bxlink:href\s*=\s*["\']\s*javascript:/i', $raw )
	) {
		$file['error'] = __( 'SVG rechazado: contiene construcciones no permitidas.', 'hwe' );
		return $file;
	}

	$clean = hwe_svg_sanitize_string( $raw );
	if ( $clean === null || $clean === '' ) {
		$file['error'] = __( 'No se pudo sanitizar el SVG. Revisa que sea un SVG válido sin scripts.', 'hwe' );
		return $file;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( $tmp, $clean ) ) {
		$file['error'] = __( 'No se pudo escribir el SVG sanitizado.', 'hwe' );
		return $file;
	}

	$file['type'] = 'image/svg+xml';
	$file['size'] = strlen( $clean );
	return $file;
}

add_filter( 'wp_handle_upload_prefilter', 'hwe_svg_sanitize_upload' );
add_filter( 'wp_handle_sideload_prefilter', 'hwe_svg_sanitize_upload' );

/**
 * ¿Es segura una URL dentro de un SVG (`href`, `xlink:href`)?
 *
 * Lista de PERMITIDOS, no de prohibidos: un fragmento (`#id`), http(s), una ruta
 * relativa sin esquema o una imagen raster en `data:`. Antes de comparar se quitan
 * espacios y caracteres de control: el navegador los ignora dentro del esquema, así
 * que `java&#x09;script:` (que el DOM entrega como «java\tscript:») ejecutaría.
 */
function hwe_svg_is_safe_url( string $value ): bool {
	$normalized = strtolower( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $value ) );
	if ( $normalized === '' || str_starts_with( $normalized, '#' ) ) {
		return true;
	}
	if ( preg_match( '#^(https?:|data:image/(png|jpe?g|gif|webp);)#', $normalized ) ) {
		return true;
	}
	// Relativa: sin esquema antes de la primera barra, `?` o `#`.
	return ! preg_match( '#^[a-z][a-z0-9+.-]*:#', $normalized );
}

/** ¿Lleva un valor de atributo un esquema ejecutable, aunque sea dentro de `url(…)`? */
function hwe_svg_has_script_scheme( string $value ): bool {
	$normalized = strtolower( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $value ) );
	return (bool) preg_match( '/javascript:|vbscript:|data:text\/html|data:image\/svg|expression\(/', $normalized );
}

/**
 * Limpia un documento SVG: elimina scripts, handlers, foreignObject, animaciones que
 * reescriben enlaces, URLs no permitidas, etc.
 *
 * @return string|null SVG limpio o null si falla.
 */
function hwe_svg_sanitize_string( string $raw ): ?string {
	if ( ! class_exists( 'DOMDocument' ) ) {
		return null;
	}
	// Entidades y hojas de estilo XML: ni se parsean (expansión de entidades,
	// XXE). La subida ya las rechaza; esto cubre a cualquier otro llamante.
	if ( preg_match( '/<!ENTITY|<\?xml-stylesheet/i', $raw ) ) {
		return null;
	}

	$previous = libxml_use_internal_errors( true );
	$dom      = new DOMDocument();
	// LIBXML_NONET evita fetch de entidades/recursos de red al parsear.
	$loaded = $dom->loadXML( $raw, LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $loaded || ! $dom->documentElement ) {
		return null;
	}

	$root = $dom->documentElement;
	if ( strtolower( $root->localName ?: $root->tagName ) !== 'svg' ) {
		return null;
	}

	$forbidden_tags = [
		'script',
		'foreignobject',
		'iframe',
		'object',
		'embed',
		'audio',
		'video',
		'handler',
		'listener',
		'style', // estilos inline en SVG pueden esconder URLs; preferimos atributos fill/stroke.
	];
	// Animaciones SMIL: pueden cambiar `href` a `javascript:` tras el saneado.
	$animation_tags = [ 'set', 'animate', 'animatemotion', 'animatetransform', 'animatecolor' ];

	$remove = [];
	foreach ( $dom->getElementsByTagName( '*' ) as $el ) {
		if ( ! $el instanceof DOMElement ) {
			continue;
		}
		$local = strtolower( $el->localName ?: $el->tagName );
		if ( in_array( $local, $forbidden_tags, true ) ) {
			$remove[] = $el;
			continue;
		}
		if ( in_array( $local, $animation_tags, true ) ) {
			$target = strtolower( trim( $el->getAttribute( 'attributeName' ) ) );
			if ( $target === 'href' || str_ends_with( $target, ':href' ) ) {
				$remove[] = $el;
				continue;
			}
		}

		if ( ! $el->hasAttributes() ) {
			continue;
		}

		$to_drop = [];
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			if ( ! $attr instanceof DOMAttr ) {
				continue;
			}
			$name  = strtolower( $attr->name );
			$value = $attr->value;

			// Event handlers (onload, onclick, …).
			if ( str_starts_with( $name, 'on' ) ) {
				$to_drop[] = $attr->nodeName;
				continue;
			}
			if ( $name === 'href' || str_ends_with( $name, ':href' ) ) {
				if ( ! hwe_svg_is_safe_url( $value ) ) {
					$to_drop[] = $attr->nodeName;
				}
				continue;
			}
			// Cualquier otro atributo (style, values, to, from, fill="url(…)"…).
			if ( hwe_svg_has_script_scheme( $value ) ) {
				$to_drop[] = $attr->nodeName;
			}
		}
		foreach ( $to_drop as $attr_name ) {
			$el->removeAttribute( $attr_name );
		}
	}

	foreach ( $remove as $el ) {
		if ( $el->parentNode ) {
			$el->parentNode->removeChild( $el );
		}
	}

	$out = $dom->saveXML( $dom->documentElement );
	return is_string( $out ) && $out !== '' ? $out : null;
}

/**
 * Evita la tubería raster (GD/Imagick + umbral 2560) sobre SVG.
 * Imagick en este stack falla al abrir SVG; un intento de “escalar” acaba en 500.
 */
add_filter( 'file_is_displayable_image', function ( bool $result, string $path ): bool {
	return hwe_svg_is_svg_filename( $path ) ? false : $result;
}, 10, 2 );

add_filter( 'big_image_size_threshold', function ( $threshold, $imagesize, string $file ) {
	return hwe_svg_is_svg_filename( $file ) ? false : $threshold;
}, 10, 3 );

add_filter( 'wp_get_missing_image_subsizes', function ( array $missing_sizes, $image_meta, int $attachment_id ): array {
	return get_post_mime_type( $attachment_id ) === 'image/svg+xml' ? [] : $missing_sizes;
}, 10, 3 );

add_filter( 'intermediate_image_sizes_advanced', function ( $sizes, $metadata ) {
	$file = is_array( $metadata ) ? (string) ( $metadata['file'] ?? '' ) : '';
	return hwe_svg_is_svg_filename( $file ) ? [] : $sizes;
}, 10, 2 );

/**
 * Dimensiones desde width/height/viewBox del SVG (fallback 512).
 *
 * @return array{0:int,1:int}
 */
function hwe_svg_read_dimensions( string $file ): array {
	$fallback = [ 512, 512 ];
	if ( ! is_readable( $file ) ) {
		return $fallback;
	}
	$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_string( $raw ) || $raw === '' ) {
		return $fallback;
	}

	$width = $height = 0;

	if ( preg_match( '/\bviewBox\s*=\s*["\']\s*([0-9.+-eE]+)\s+([0-9.+-eE]+)\s+([0-9.+-eE]+)\s+([0-9.+-eE]+)\s*["\']/', $raw, $m ) ) {
		$width  = (int) round( (float) $m[3] );
		$height = (int) round( (float) $m[4] );
	}
	if ( preg_match( '/\bwidth\s*=\s*["\']\s*([0-9.]+)\s*(?:px)?\s*["\']/', $raw, $m ) ) {
		$width = (int) round( (float) $m[1] );
	}
	if ( preg_match( '/\bheight\s*=\s*["\']\s*([0-9.]+)\s*(?:px)?\s*["\']/', $raw, $m ) ) {
		$height = (int) round( (float) $m[1] );
	}

	if ( $width < 1 ) {
		$width = $fallback[0];
	}
	if ( $height < 1 ) {
		$height = $fallback[1];
	}
	// Cap para no disparar umbrales raros en clientes/admin; el SVG sigue siendo vectorial.
	$width  = min( $width, 4096 );
	$height = min( $height, 4096 );

	return [ $width, $height ];
}

/**
 * Metadatos de adjunto SVG: dimensiones reales + sin tamaños intermedios.
 *
 * @param array<string,mixed>|false $metadata
 * @return array<string,mixed>|false
 */
add_filter( 'wp_generate_attachment_metadata', function ( $metadata, int $attachment_id ) {
	$mime = get_post_mime_type( $attachment_id );
	if ( $mime !== 'image/svg+xml' ) {
		return $metadata;
	}
	if ( ! is_array( $metadata ) ) {
		$metadata = [];
	}

	$file = get_attached_file( $attachment_id );
	[ $width, $height ] = is_string( $file ) && $file !== ''
		? hwe_svg_read_dimensions( $file )
		: [ 512, 512 ];

	$metadata['width']  = $width;
	$metadata['height'] = $height;
	$metadata['sizes']  = [];
	if ( empty( $metadata['file'] ) && is_string( $file ) ) {
		$metadata['file'] = _wp_relative_upload_path( $file );
	}
	if ( empty( $metadata['filesize'] ) && is_string( $file ) && is_readable( $file ) ) {
		$metadata['filesize'] = wp_filesize( $file );
	}
	return $metadata;
}, 10, 2 );

/**
 * Preview en el modal de medios (JS attachment).
 *
 * @param array<string,mixed> $response
 * @return array<string,mixed>
 */
add_filter( 'wp_prepare_attachment_for_js', function ( array $response, WP_Post $attachment ): array {
	if ( ( $response['mime'] ?? '' ) !== 'image/svg+xml' ) {
		return $response;
	}
	$url = $response['url'] ?? wp_get_attachment_url( $attachment->ID );
	if ( ! is_string( $url ) || $url === '' ) {
		return $response;
	}
	$response['sizes'] = [
		'full' => [
			'url'         => $url,
			'width'       => $response['width'] ?? 512,
			'height'      => $response['height'] ?? 512,
			'orientation' => 'portrait',
		],
	];
	$response['icon'] = $url;
	return $response;
}, 10, 2 );
