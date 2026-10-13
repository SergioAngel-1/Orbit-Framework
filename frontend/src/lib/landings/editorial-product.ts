import "server-only";
import { cache } from "react";
import { getProductBySlug } from "@/lib/catalog/products";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import type { SiteConfig } from "@/lib/config/types";
import { pickLocalized } from "@/lib/i18n/pick-localized";
import { productLearnHref } from "@/lib/landings/product-learn-href";
import { buildEditorialRegistry } from "@/lib/landings/registry";

// ============================================================================
//  Landing editorial ASOCIADA A UN PRODUCTO (campo «Producto asociado a esta
//  landing»): la guía del producto la escribe el editor en el panel de landings
//  en vez de en la caja de la ficha.
//
//  Se sirve en la MISMA URL que la landing de producto (`/products/[slug]/learn`)
//  y NO tiene URL libre: `buildEditorialRegistry` (en su forma con `editorial`) la
//  excluye. Precedencia, que decide la ruta: landing de producto publicada >
//  editorial asociada > 404.
//
//  Reglas de `findEditorialLandingForProduct`: coincidencia EXACTA del slug nativo
//  del producto, landing PUBLICADA y REGISTRADA (sin eso `getEditorialLanding` la
//  da por inexistente y la ruta serviría un 404), y con dos landings sobre el mismo
//  producto gana la primera del registro (un error de configuración raro, no algo
//  que deba romper la página).
// ============================================================================

export interface ProductLandingLink {
  slug: string;
  ctaLabel: string | null;
}

const text = (value: unknown): string | null =>
  typeof value === "string" && value.trim() !== "" ? value.trim() : null;

/** Puro: toda la lógica vive aquí para poder probarla sin la config viva. */
export function findEditorialLandingForProduct(
  config: Pick<SiteConfig, "landings" | "modules">,
  productSlug: string,
  locale: string,
): ProductLandingLink | null {
  if (!isModuleEnabled(config, "landings")) return null;
  const wanted = text(productSlug);
  if (!wanted) return null;

  // La forma de 1 argumento SÍ ve las landings asociadas a un producto (la de 2, para URL
  // libre, las excluye).
  const registry = buildEditorialRegistry(config.landings.registry);

  for (const [slug, cfg] of Object.entries(config.landings.editorial ?? {})) {
    if (!cfg || cfg.published !== true) continue;
    if (text(cfg.product) !== wanted) continue;
    if (!registry[slug]) continue;
    return { slug, ctaLabel: text(pickLocalized(cfg, "product_cta_label", locale)) };
  }
  return null;
}

export const getEditorialLandingForProduct = cache(
  async (productSlug: string, locale: string): Promise<ProductLandingLink | null> => {
    return findEditorialLandingForProduct(await getSiteConfig(), productSlug, locale);
  },
);

/**
 * `/products/<tramo del idioma>/learn` de un producto por su slug nativo, o `null` si el
 * producto no existe: un enlace a la landing de un producto borrado es un 404, y quien la
 * enlaza prefiere no pintar botón a pintar uno roto.
 */
export const resolveProductLearnHref = cache(
  async (productSlug: string, locale: string): Promise<string | null> => {
    const wanted = text(productSlug);
    if (!wanted) return null;
    const product = await getProductBySlug(wanted, locale);
    return product ? productLearnHref(product) : null;
  },
);
