import "server-only";
import { cache } from "react";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { cleanExcerpt } from "@/lib/format";
import { fetchLocalizedGraphQL } from "@/lib/i18n/fetch-localized";
import { pickTranslated } from "@/lib/i18n/translation";
import { localizedUrlSlug } from "@/lib/i18n/url-slug";
import { getPublishedProductLandingSlugs } from "@/lib/landings/product-landing";
import { productLearnHref } from "@/lib/landings/product-learn-href";
import { RELATED_LANDING_PRODUCTS_QUERY } from "@/lib/landings/queries";

// ============================================================================
//  «Sigue aprendiendo»: otros productos con landing PUBLICADA, para la columna
//  que acompaña al video y al cierre. Se resuelve en el servidor y se pasa como
//  prop a la vista.
//
//  Fuente: `getPublishedProductLandingSlugs()` (1 fetch, sin N+1) y una consulta
//  por lote (`slugIn`) sobre ese conjunto. QUÉ tarjetas toca a cada landing lo
//  decide `pickRelatedSlugs`: un ANILLO round-robin. No hay categorías, etiquetas
//  ni ningún otro dato que emparente unas landings con otras, así que el reparto
//  es arbitrario a propósito. Se le exigen tres cosas:
//    · distinto en cada landing,
//    · ESTABLE en el tiempo — la ficha es ISR, y un azar de verdad cambiaría las
//      tarjetas en cada regeneración,
//    · y con reparto UNIFORME: que ninguna landing acapare las recomendaciones ni
//      se quede sin aparecer nunca.
//
//  RESUMEN de la tarjeta: la descripción corta de WooCommerce si la hay y, si no,
//  el subtítulo del hero de su landing. No se cae a la descripción larga: es un
//  bloque de varios párrafos que habría que cortar a mitad de frase.
// ============================================================================

/** Hash FNV-1a de 32 bits: barato, sin dependencias y bien repartido. */
function hashString(value: string): number {
  let hash = 0x811c9dc5;
  for (let i = 0; i < value.length; i += 1) {
    hash ^= value.charCodeAt(i);
    hash = Math.imul(hash, 0x01000193);
  }
  return hash >>> 0;
}

/** PRNG determinista de 32 bits (mulberry32). */
function makeRandom(seed: number): () => number {
  let state = seed >>> 0;
  return () => {
    state = (state + 0x6d2b79f5) >>> 0;
    let t = state;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/**
 * Semilla FIJA del anillo. No es el slug de la landing: el anillo es uno solo
 * para todo el sitio (ver `pickRelatedSlugs`).
 */
export const RING_SEED = "hwe-product-landings";

/**
 * Las `limit` landings que acompañan a `currentSlug`, como un anillo: se barajan
 * TODAS las publicadas una sola vez con una semilla fija y cada landing
 * recomienda a las que la siguen en ese orden circular.
 *
 * Barajar por separado en cada landing (semilla = su propio slug) también daba
 * variedad, pero sin reparto garantizado: dos landings podían sacar el mismo
 * trío por casualidad y unas salían el triple de veces que otras. Con el anillo,
 * cada landing aparece exactamente `limit` veces en todo el sitio y no hay dos
 * tríos iguales.
 */
export function pickRelatedSlugs(
  allSlugs: readonly string[],
  currentSlug: string,
  limit: number,
): string[] {
  const ring = shuffleForSlug(allSlugs, RING_SEED);
  const start = ring.indexOf(currentSlug);
  // Slug no publicado (no está en el anillo): se arranca por el principio.
  const from = start === -1 ? 0 : start + 1;
  const out: string[] = [];
  for (let i = 0; i < ring.length - 1 && out.length < limit; i += 1) {
    const slug = ring[(from + i) % ring.length];
    if (slug !== currentSlug) out.push(slug);
  }
  return out;
}

/**
 * Fisher-Yates sembrado por `seed`: la misma semilla da siempre el mismo orden.
 * No muta la entrada.
 */
export function shuffleForSlug<T>(items: readonly T[], seed: string): T[] {
  const out = [...items];
  const random = makeRandom(hashString(seed));
  for (let i = out.length - 1; i > 0; i -= 1) {
    const j = Math.floor(random() * (i + 1));
    [out[i], out[j]] = [out[j], out[i]];
  }
  return out;
}

export interface RelatedProductLanding {
  name: string;
  /** Ruta interna de la landing del producto (sin prefijo de idioma). */
  href: string;
  image: string | null;
  /** Una línea de resumen, ya limpia de HTML. Puede ser "". */
  excerpt: string;
}

type RawNode = Record<string, unknown> & {
  slug: string;
  name: string | null;
  image: { sourceUrl: string | null } | null;
  shortDescription?: string | null;
  landing: Record<string, unknown> | null;
};

/** Máximo de la línea de resumen: dos líneas de tarjeta. */
const EXCERPT_MAX_CHARS = 110;

/** Extracto de Woo si lo hay; si no, el subtítulo del hero de su landing. */
function pickExcerpt(node: RawNode, locale: string): string {
  const short = cleanExcerpt(
    pickTranslated(node, "shortDescription", locale),
    EXCERPT_MAX_CHARS,
  );
  if (short !== "") return short;
  return cleanExcerpt(
    pickTranslated(node.landing, "heroSubtitle", locale),
    EXCERPT_MAX_CHARS,
  );
}

export const getRelatedProductLandings = cache(
  async (
    currentSlug: string,
    locale: string,
    limit = 3,
  ): Promise<RelatedProductLanding[]> => {
    if (!isModuleEnabled(await getSiteConfig(), "product-landing")) return [];

    // El anillo se construye sobre TODAS las publicadas, la actual incluida: es
    // su posición en él la que decide por dónde empieza a recomendar.
    const allSlugs = await getPublishedProductLandingSlugs();
    const chosen = pickRelatedSlugs(allSlugs, currentSlug, limit);
    if (chosen.length === 0) return [];

    const data = await fetchLocalizedGraphQL<{ products: { nodes: RawNode[] } | null }>(
      RELATED_LANDING_PRODUCTS_QUERY,
      locale,
      { variables: { slugs: chosen }, revalidate: 3600, tags: ["products"] },
    );
    const bySlug = new Map(
      (data.products?.nodes ?? []).map((node) => [node.slug, node]),
    );

    // Un slug publicado cuyo producto ya no devuelve WPGraphQL (borrado o
    // despublicado entre las dos lecturas) no aporta tarjeta; no se rellena con
    // otro para no desalinear el anillo.
    return chosen.flatMap((slug) => {
      const node = bySlug.get(slug);
      if (!node) return [];
      return [
        {
          name: pickTranslated(node, "name", locale) ?? node.slug,
          href: productLearnHref({
            slug: node.slug,
            urlSlug: localizedUrlSlug(node, locale),
          }),
          image: node.image?.sourceUrl ?? null,
          excerpt: pickExcerpt(node, locale),
        },
      ];
    });
  },
);
