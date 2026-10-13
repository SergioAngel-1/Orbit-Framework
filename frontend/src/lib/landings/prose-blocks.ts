// ============================================================================
//  Parte un richtext del CMS en una intro y N bloques encabezados por `<h3>`.
//
//  Lo usa el layout `prose-blocks` de `section-content` para pintar en
//  columnas lo que el cliente escribe como un solo cuerpo en el `wp_editor`
//  del Control Center. Sin `<h3>` devuelve `blocks: []` y la sección cae a
//  prosa corrida: es degradación, no error.
//
//  No sanea: el HTML ya llega saneado de `editorial-landing.ts` y `LandingProse`
//  lo vuelve a sanear al pintarlo.
// ============================================================================

export interface ProseBlockCta {
  href: string;
  label: string;
}

export interface ProseBlock {
  heading: string;
  /** HTML del cuerpo del bloque (puede ser cadena vacía). */
  html: string;
  /**
   * Enlaces de cierre del bloque, extraídos del cuerpo: los párrafos FINALES
   * que contienen un solo `<a>` y nada más, en orden de lectura. El layout
   * los pinta como CTA en vez de dejarlos sueltos dentro de la prosa, así que
   * el cliente decide desde el `wp_editor` si un bloque lleva llamada a la
   * acción — y si lleva una o dos (p. ej. sitio externo + página del sitio).
   * Vacío cuando el bloque no termina en enlaces.
   */
  ctas: ProseBlockCta[];
}

export interface ProseBlocks {
  /** HTML anterior al primer `<h3>`. */
  intro: string;
  blocks: ProseBlock[];
}

const H3 = /<h3\b[^>]*>([\s\S]*?)<\/h3>/gi;

/** Texto plano de un encabezado: fuera etiquetas internas, espacios colapsados. */
function headingText(inner: string): string {
  return inner
    .replace(/<[^>]*>/g, "")
    .replace(/\s+/g, " ")
    .trim();
}

/**
 * Último `<p>` del cuerpo, si existe. El contenido no puede contener otra
 * etiqueta `p`: sin esa restricción el cuantificador perezoso arranca en el
 * PRIMER `<p>` y se traga todos los párrafos intermedios hasta el `</p>` final.
 */
const TRAILING_P = /<p\b[^>]*>((?:(?!<\/?p\b)[\s\S])*)<\/p>\s*$/i;
/** Un `<a href>` que ocupa TODO el párrafo (nada de texto suelto alrededor). */
const LONE_LINK = /^\s*<a\b[^>]*\bhref=["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>\s*$/i;

/**
 * Separa del cuerpo los párrafos finales que son solo un enlace. Se recorre
 * de atrás hacia delante y se para en el primer párrafo que no lo sea, así que
 * un enlace en mitad de la prosa nunca se convierte en CTA.
 */
function extractTrailingCtas(html: string): { html: string; ctas: ProseBlockCta[] } {
  let rest = html;
  const ctas: ProseBlockCta[] = [];

  for (;;) {
    const paragraph = rest.match(TRAILING_P);
    if (!paragraph) break;

    const link = paragraph[1].match(LONE_LINK);
    if (!link) break;

    const label = headingText(link[2]);
    if (label === "") break;

    ctas.unshift({ href: link[1].trim(), label });
    rest = rest.slice(0, paragraph.index).trim();
  }

  return { html: rest, ctas };
}

export function splitByH3(html: string | null | undefined): ProseBlocks {
  if (typeof html !== "string" || html.trim() === "") {
    return { intro: "", blocks: [] };
  }

  const matches = [...html.matchAll(H3)];
  if (matches.length === 0) return { intro: html.trim(), blocks: [] };

  const intro = html.slice(0, matches[0].index).trim();
  const blocks: ProseBlock[] = matches.map((match, i) => {
    const start = match.index + match[0].length;
    const end = i + 1 < matches.length ? matches[i + 1].index : html.length;
    const body = extractTrailingCtas(html.slice(start, end).trim());
    return { heading: headingText(match[1]), html: body.html, ctas: body.ctas };
  });

  return { intro, blocks };
}
