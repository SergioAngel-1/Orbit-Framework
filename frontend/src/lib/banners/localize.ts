import "server-only";
import { routing } from "@/i18n/routing";
import { getCategories, getProductsBySlugs } from "@/lib/catalog/products";
import { logReadFailure } from "@/lib/observability/read-failure";
import type { BannerPlacement, BannerSlide } from "./types";

// ============================================================================
//  Enlaces de un banner: UNA ruta interna por enlace, para todos los idiomas.
//
//  El CMS guarda la ruta interna con los slugs NATIVOS (`/products/vela-roja`,
//  `/categories/velas`) y no hay campo de URL por idioma: aquí se lleva cada
//  enlace del catálogo al slug de SU idioma (`/products/red-candle` en /en), igual
//  que hacen las tarjetas y los menús, y `Link` pone el tramo público de la ruta
//  (`pathnames` de next-intl). Sin esto el enlace pasaba por un 308 hacia el slug
//  del idioma —una redirección que señalan las auditorías SEO—.
//
//  Vale para CUALQUIER idioma del sitio (`routing.locales`); en el idioma por
//  defecto los slugs nativos ya son los de la URL y no se consulta nada.
// ============================================================================

const HREF_KEYS = ["ctaHref", "cta2Href", "link"] as const;
const CATALOG_PATH = /^\/(products|categories)\/([^/?#]+)(.*)$/;

type CatalogKind = "products" | "categories";

export function parseCatalogHref(
  href: string,
): { kind: CatalogKind; slug: string; rest: string } | null {
  const match = CATALOG_PATH.exec(href);
  if (!match) return null;
  let slug = match[2];
  try {
    slug = decodeURIComponent(slug);
  } catch {
    /* un % mal formado: se usa tal cual */
  }
  return { kind: match[1] as CatalogKind, slug, rest: match[3] };
}

/** Slugs nativos de producto y de categoría que enlazan los slides. */
export function catalogSlugs(slides: readonly BannerSlide[]): {
  products: string[];
  categories: string[];
} {
  const products = new Set<string>();
  const categories = new Set<string>();
  for (const slide of slides) {
    for (const key of HREF_KEYS) {
      const parsed = parseCatalogHref(slide[key] ?? "");
      if (parsed) (parsed.kind === "products" ? products : categories).add(parsed.slug);
    }
  }
  return { products: [...products], categories: [...categories] };
}

export interface CatalogSlugResolvers {
  /** Slug de la URL en el idioma, o `undefined` si el producto no se encuentra. */
  product: (nativeSlug: string) => string | undefined;
  category: (nativeSlug: string) => string | undefined;
}

/** Lleva cada enlace del catálogo al slug de su idioma; lo que no resuelve se deja como está. */
export function localizeSlideHrefs(
  slides: BannerSlide[],
  resolve: CatalogSlugResolvers,
): BannerSlide[] {
  return slides.map((slide) => {
    const out = { ...slide };
    for (const key of HREF_KEYS) {
      const href = slide[key];
      if (!href) continue;
      const parsed = parseCatalogHref(href);
      if (!parsed) continue;
      const slug = (parsed.kind === "products" ? resolve.product : resolve.category)(
        parsed.slug,
      );
      if (slug) out[key] = `/${parsed.kind}/${encodeURIComponent(slug)}${parsed.rest}`;
    }
    return out;
  });
}

/**
 * Fail-soft: si el catálogo no responde, los enlaces se quedan como los guardó el
 * CMS (redirigen, no rompen) y el fallo queda registrado. No consulta nada en el
 * idioma por defecto ni si ningún slide enlaza al catálogo, que es el caso de casi
 * todos los banners.
 */
export async function localizePlacement(
  placement: BannerPlacement,
  locale: string,
): Promise<BannerPlacement> {
  if (locale === routing.defaultLocale) return placement;
  const { products, categories } = catalogSlugs(placement.slides);
  if (products.length === 0 && categories.length === 0) return placement;
  try {
    const [productList, categoryList] = await Promise.all([
      products.length > 0 ? getProductsBySlugs(products, locale) : Promise.resolve([]),
      categories.length > 0 ? getCategories(locale) : Promise.resolve([]),
    ]);
    const productSlugs = new Map(productList.map((p) => [p.slug, p.urlSlug ?? p.slug]));
    const categorySlugs = new Map(
      categoryList.map((c) => [c.slug, c.urlSlug ?? c.slug]),
    );
    return {
      ...placement,
      slides: localizeSlideHrefs(placement.slides, {
        product: (slug) => productSlugs.get(slug),
        category: (slug) => categorySlugs.get(slug),
      }),
    };
  } catch (error) {
    logReadFailure("banners.localize_failed", error, {
      level: "warn",
      context: { locale },
      message:
        "No se pudieron localizar los enlaces de los banners: se dejan como están",
    });
    return placement;
  }
}
