<?php

namespace HWE\Banners;

/**
 * Guías de longitud/relleno para campos de texto del slide (admin UX).
 *
 * Rangos orientativos para el copy de un hero (título corto, entradilla de 1–2 frases,
 * botones de 2–3 palabras). Una instancia los ajusta a sus plantillas con
 * `hwe_banners_field_hints`.
 *
 * Soft guidance: no valida ni trunca.
 */
class FieldHints {

	/**
	 * @return array<string,array{chars?:string,note:string}>
	 */
	public static function all(): array {
		$hints = [
			'title' => [
				'chars' => '28–40',
				'note'  => __( 'Rellena este campo (como en el hero de la portada). Por encima de ~50 caracteres el título se parte en demasiadas líneas.', 'hwe-banners' ),
			],
			'subtitle' => [
				'chars' => '90–120',
				'note'  => __( '1–2 frases, como las descripciones del hero. Más de ~140 caracteres aprieta el bloque (máx. ~46 caracteres por línea en CSS).', 'hwe-banners' ),
			],
			'badge' => [
				'chars' => '20–36',
				'note'  => __( 'Kicker corto (p. ej. «Novedades · Otoño»). Evita pasar de ~40 caracteres: el tracking amplio lo alarga mucho.', 'hwe-banners' ),
			],
			'cta' => [
				'chars' => '12–22',
				'note'  => __( 'Texto del botón (p. ej. «Ver la tienda»). Si lo usas, completa también la URL. Evita frases tipo párrafo.', 'hwe-banners' ),
			],
			'cta2' => [
								'chars' => '16–28',
				'note'  => __( 'Enlace secundario (p. ej. «Más información →»). Si lo usas, completa también su URL.', 'hwe-banners' ),
			],
			'cta_href' => [
				'note' => __( 'Obligatorio si hay texto de CTA. Ruta INTERNA del sitio, la misma para todos los idiomas: /products, /categories/mi-categoria, /blog/mi-entrada. No pegues la dirección pública (/productos/…), ni /en/…, ni el dominio: provocan redirecciones. Para otro sitio web, la URL completa (https://…).', 'hwe-banners' ),
			],
			'cta2_href' => [
				'note' => __( 'Obligatorio si hay texto de CTA 2. Ruta INTERNA del sitio, la misma para todos los idiomas: /products, /categories/mi-categoria, /blog/mi-entrada. No pegues la dirección pública (/productos/…), ni /en/…, ni el dominio: provocan redirecciones. Para otro sitio web, la URL completa (https://…).', 'hwe-banners' ),
			],
			'image_alt' => [
				'note' => __( 'Describe la imagen para lectores de pantalla y buscadores (p. ej. «Oferta del mes de septiembre»). Imprescindible si el slide es solo imagen con enlace: sin él el enlace no tiene nombre. Vacío = se usa el título.', 'hwe-banners' ),
			],
			'link' => [
				'note' => __( 'Opcional: hace clicable la imagen / todo el slide. Ruta INTERNA del sitio, la misma para todos los idiomas: /products, /categories/mi-categoria, /blog/mi-entrada. No pegues la dirección pública (/productos/…), ni /en/…, ni el dominio: provocan redirecciones. Para otro sitio web, la URL completa (https://…).', 'hwe-banners' ),
			],
		];

		/**
		 * Filtra las guías de campos de texto del admin de banners.
		 *
		 * @param array<string,array{chars?:string,note:string}> $hints
		 */
		return apply_filters( 'hwe_banners_field_hints', $hints );
	}

	/** Texto de disclaimer para un campo, o cadena vacía si no hay guía. */
	public static function for( string $field ): string {
		$hints = self::all();
		if ( ! isset( $hints[ $field ] ) ) {
			return '';
		}
		$h = $hints[ $field ];
		$note = (string) ( $h['note'] ?? '' );
		$chars = isset( $h['chars'] ) ? (string) $h['chars'] : '';
		if ( $chars !== '' ) {
			return sprintf(
				/* translators: 1: recommended character range (e.g. 28–40), 2: extra note */
				__( 'Recomendado: %1$s caracteres. %2$s', 'hwe-banners' ),
				$chars,
				$note
			);
		}
		return $note;
	}
}
