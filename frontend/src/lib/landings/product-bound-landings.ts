import "server-only";
import { cache } from "react";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import type { SiteConfig } from "@/lib/config/types";
import { routing } from "@/i18n/routing";
import { editorialTranslatedLocales } from "@/lib/landings/editorial-locales";
import {
  getPublishedProductLandingLocales,
  getPublishedProductLandingSlugs,
} from "@/lib/landings/product-landing";
import { buildEditorialRegistry } from "@/lib/landings/registry";

// ============================================================================
//  Slugs de PRODUCTO que tienen una landing publicada, sea cual sea el
//  mecanismo que la produce:
//
//   · landing de producto — `Product.landing`, caja de la ficha (módulo
//     `product-landing`);
//   · editorial asociada — `landings.editorial.<id>.product` (módulo `landings`).
//
//  Los dos se sirven en la MISMA forma de URL (`/products/<slug>/learn`), así que
//  quien liste landings de producto —el sitemap y el índice del área— necesita
//  exactamente esta unión. Aquí queda en un solo sitio para que los dos
//  consumidores no diverjan.
// ============================================================================

/** Puro: la mitad editorial de la unión, probable sin config viva. */
export function editorialProductSlugs(
  config: Pick<SiteConfig, "landings" | "modules">,
): string[] {
  if (!isModuleEnabled(config, "landings")) return [];
  const registry = buildEditorialRegistry(config.landings.registry);
  return Object.entries(config.landings.editorial ?? {})
    .filter(
      ([slug, cfg]) =>
        cfg?.published === true &&
        Boolean(registry[slug]) &&
        typeof cfg.product === "string" &&
        cfg.product.trim() !== "",
    )
    .map(([, cfg]) => (cfg!.product as string).trim());
}

/**
 * La unión de los dos mecanismos. Un fallo al consultar los slugs de la landing de producto no
 * deja sin índice a las editoriales (`getPublishedProductLandingSlugs` degrada a vacío y lo
 * registra): el llamante decide qué hacer con un conjunto vacío, pero nunca recibe una
 * excepción a medias.
 */
export const getProductBoundLandingSlugs = cache(async (): Promise<Set<string>> => {
  const [config, published] = await Promise.all([
    getSiteConfig(),
    getPublishedProductLandingSlugs(),
  ]);
  return new Set([...published, ...editorialProductSlugs(config)]);
});

/**
 * Idiomas de cada landing editorial asociada a un producto (`slug del producto → idiomas`): el
 * por defecto siempre y uno secundario si la landing trae traducción de sus campos de peso.
 * Pura. La primera landing del registro sobre un producto manda (como en la ficha).
 */
function editorialProductLocales(
  config: Pick<SiteConfig, "landings" | "modules">,
): Record<string, string[]> {
  if (!isModuleEnabled(config, "landings")) return {};
  const registry = buildEditorialRegistry(config.landings.registry);
  const out: Record<string, string[]> = {};
  for (const [slug, cfg] of Object.entries(config.landings.editorial ?? {})) {
    if (cfg?.published !== true || !registry[slug]) continue;
    const product = typeof cfg.product === "string" ? cfg.product.trim() : "";
    if (product === "" || out[product]) continue;
    out[product] = editorialTranslatedLocales(cfg);
  }
  return out;
}

/**
 * Idiomas de cada landing de producto, sea cual sea el mecanismo. La landing de producto
 * (Product.landing) gana sobre la editorial asociada: es la precedencia de la ruta.
 */
export const getProductBoundLandingLocales = cache(
  async (): Promise<Record<string, string[]>> => {
    const [config, plan] = await Promise.all([
      getSiteConfig(),
      getPublishedProductLandingLocales(),
    ]);
    return { ...editorialProductLocales(config), ...plan };
  },
);

/**
 * ¿Hay una landing de producto (de cualquier mecanismo) para enlazar desde `locale`? El idioma
 * por defecto basta con estar publicada; uno secundario exige que la landing esté traducida.
 * Una lectura fallida da `false`: sin verificar, no se enlaza.
 */
export async function hasProductBoundLanding(
  slug: string,
  locale: string,
): Promise<boolean> {
  if (!(await getProductBoundLandingSlugs()).has(slug)) return false;
  if (locale === routing.defaultLocale) return true;
  return (await getProductBoundLandingLocales())[slug]?.includes(locale) ?? false;
}
