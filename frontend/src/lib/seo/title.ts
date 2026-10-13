// ============================================================================
//  Largo del `<title>`. Lógica PURA, compartida por el layout (que arma la
//  plantilla) y por las páginas que escriben su propio título.
//
//  Google corta el título hacia los 60 caracteres, y la plantilla del sitio
//  (Control Center → SEO & GEO, `seo.title_template`, por defecto `%s · %site%`)
//  añade ` · <marca>` a CADA título: un título de 60 sale de 75 y lo que Google
//  enseña acaba siendo la plantilla, no lo que se escribió.
// ============================================================================

/** Largo a partir del cual Google corta el título en los resultados. */
export const TITLE_MAX_CHARS = 60;

export const DEFAULT_TITLE_TEMPLATE = "%s · %site%";

/**
 * La plantilla de Next (`%s · Mi Marca`): `%site%` se sustituye aquí por el
 * nombre de la marca y `%s` lo resuelve Next con el título de cada página.
 */
export function resolveTitleTemplate(
  template: string | null | undefined,
  siteName: string,
): string {
  return (template || DEFAULT_TITLE_TEMPLATE).replaceAll("%site%", siteName);
}

/**
 * Valor de `metadata.title` para una página que escribe su propio título: el
 * texto tal cual si con el sufijo de la plantilla cabe en `max` (Next lo
 * añade), y si no, `{ absolute }` —sin sufijo—. NUNCA recorta el título: lo que
 * se escribe es del autor; lo que sobra es la marca, no sus palabras.
 */
export function fitTitle(
  title: string,
  template: string,
  max: number = TITLE_MAX_CHARS,
): string | { absolute: string } {
  if (!template.includes("%s")) return title;
  const suffixLength = template.replace("%s", "").length;
  return title.length + suffixLength <= max ? title : { absolute: title };
}

/** Separadores que un título SEO usa antes de la marca: «X | Marca», «X – Marca». */
const BRAND_SEPARATOR = String.raw`\s*[|·\-–—:]\s*`;

function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

/**
 * Título de una página cuyo texto puede traer YA la marca escrita.
 *
 * Los títulos SEO importados de una web anterior suelen terminar en «| Marca»
 * o «- Marca Antigua», y la plantilla del sitio les añadía otra marca detrás.
 * Aquí se retira del FINAL del título cualquier nombre de la marca —el actual
 * o los de `seo.alternate_names`— y después decide `fitTitle`. Un título que
 * es solo la marca va sin plantilla.
 *
 * Solo toca el final y solo tras un separador: «Fórmulas Marca» no se recorta.
 */
export function brandedTitle(
  title: string,
  template: string,
  siteName: string,
  aliases: readonly string[] = [],
  max: number = TITLE_MAX_CHARS,
): string | { absolute: string } {
  const names = [siteName, ...aliases]
    .map((name) => name.trim())
    .filter(Boolean)
    // El más largo primero: «Marca Florales» antes que «Marca».
    .sort((a, b) => b.length - a.length);
  let text = title.trim();
  if (names.some((name) => name.toLowerCase() === text.toLowerCase())) {
    return { absolute: text };
  }
  if (names.length > 0) {
    const suffix = new RegExp(
      `${BRAND_SEPARATOR}(?:${names.map(escapeRegExp).join("|")})$`,
      "i",
    );
    // Más de una vuelta: «X | Línea – Marca» lleva dos.
    for (let i = 0; i < 3 && suffix.test(text); i += 1) {
      text = text.replace(suffix, "").trim();
    }
  }
  return fitTitle(text || title.trim(), template, max);
}

/**
 * `metadata.title` de una página a partir de la config del sitio: la plantilla
 * y los nombres de la marca salen de Control Center → SEO & GEO.
 */
export function siteTitle(
  config: {
    brand: { name: string };
    seo: { title_template?: string | null; alternate_names?: string | null };
  },
  title: string,
): string | { absolute: string } {
  return brandedTitle(
    title,
    resolveTitleTemplate(config.seo.title_template, config.brand.name),
    config.brand.name,
    (config.seo.alternate_names || "").split(/[\n,]/),
  );
}
