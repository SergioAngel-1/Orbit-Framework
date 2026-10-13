import { cleanExcerpt } from "@/lib/format";

// ============================================================================
//  Meta description: el texto que Google pinta bajo el título del resultado,
//  y el que viaja en `og:description` cuando la página no declara otro.
//
//  Un `.slice(0, 160)` crudo parte la última palabra por la mitad y la deja a
//  la vista. El tope es necesario —por encima Google recorta él, y lo hace
//  peor—, pero el corte tiene que caer en un espacio.
//
//  No se reimplementa el recorte: `cleanExcerpt` ya limpia HTML y entidades,
//  corta por palabra completa, no deja puntuación colgando y cierra con puntos
//  suspensivos. Lo único que añade esta capa es aplastar el salto de bloque a
//  un espacio: `EXCERPT_SEPARATOR` es un `\n` real, pensado para una tarjeta con
//  `whitespace-pre-line`, y dentro de un atributo `content` no pinta nada bueno.
// ============================================================================

/**
 * Tope por defecto, en caracteres. 160 es lo que los buscadores suelen mostrar
 * en escritorio; pasarse no es un error, solo se recorta.
 */
export const META_DESCRIPTION_MAX = 160;

/**
 * Texto (o HTML del CMS) listo para una `<meta name="description">`.
 *
 * @returns Cadena vacía si no hay texto: el llamante decide si eso es
 *   `undefined` (no emitir la etiqueta) o un respaldo.
 */
export function metaDescription(
  source?: string | null,
  maxChars: number = META_DESCRIPTION_MAX,
): string {
  return cleanExcerpt(source, maxChars).replace(/\s*\n\s*/g, " ");
}
