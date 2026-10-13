import "server-only";
import { getProducts, getProductsBySlugs } from "@/lib/catalog/products";
import { getCartProductRefs } from "@/lib/catalog/coupon-refs";
import {
  extractProductSlugsFromHtml,
  rewriteProductLinks,
} from "@/lib/blog/extract-product-links";
import {
  extractRecs,
  preparePostHeadings,
  splitPostHtml,
  type PostHeading,
  type PostSegment,
} from "@/lib/blog/post-content";
import { resolveRecs, type ResolvedRec } from "@/lib/blog/resolve-recs";
import {
  buildMentionedProductsStrip,
  MENTIONED_PRODUCTS_MAX,
} from "@/lib/blog/related-products";
import type { CatalogProduct } from "@/types/catalog";

export interface PostView {
  /** Prosa y recomendaciones en orden; la prosa SIN sanear (lo hace quien la pinta). */
  segments: PostSegment[];
  recs: Array<ResolvedRec | null>;
  headings: PostHeading[];
  /** Franja «Productos mencionados» (con relleno de parecidos si no llega a 4). */
  mentioned: { items: CatalogProduct[]; hasRelated: boolean };
}

/**
 * Todo lo que la vista de una entrada necesita del cuerpo HTML del CMS:
 *
 *  1. Los productos que el cuerpo enlaza (`/products/x`, y el `/producto/x` de la
 *     web anterior) se resuelven en UNA consulta; los enlaces heredados se
 *     reescriben a la ficha real y el que no resuelve se deja como está.
 *  2. Los marcadores de recomendación se sustituyen y se resuelven.
 *  3. Los encabezados reciben `id` y alimentan la TOC.
 *
 * Fail-soft: sin catálogo la entrada se pinta igual, sin franja ni
 * recomendaciones de producto.
 */
export async function buildPostView(
  rawHtml: string,
  opts: { locale: string; slug: string; siteOrigins: readonly string[] },
): Promise<PostView> {
  const slugs = extractProductSlugsFromHtml(rawHtml, { siteOrigins: opts.siteOrigins });

  let mentionedProducts: CatalogProduct[] = [];
  if (slugs.length > 0) {
    try {
      mentionedProducts = await getProductsBySlugs(slugs.slice(0, 24));
    } catch {
      mentionedProducts = [];
    }
  }
  const known = new Set(mentionedProducts.map((product) => product.slug));

  const rewritten = rewriteProductLinks(
    rawHtml,
    (slug) => (known.has(slug) ? `/products/${slug}` : null),
    { siteOrigins: opts.siteOrigins },
  );
  const { html: withMarkers, recs } = extractRecs(rewritten);
  const { html, headings } = preparePostHeadings(withMarkers);

  const resolved = await resolveRecs(recs, {
    locale: opts.locale,
    currentPostSlug: opts.slug,
  });

  return {
    segments: splitPostHtml(html),
    recs: resolved,
    headings,
    mentioned: await mentionedStrip(mentionedProducts),
  };
}

/**
 * Franja de productos mencionados, completada hasta una fila con productos de la
 * MISMA categoría del primero mencionado. El relleno cuesta dos consultas
 * cacheadas y solo cuando hay menciones y no llegan al máximo.
 */
async function mentionedStrip(
  mentioned: CatalogProduct[],
): Promise<{ items: CatalogProduct[]; hasRelated: boolean }> {
  if (mentioned.length === 0 || mentioned.length >= MENTIONED_PRODUCTS_MAX) {
    return { items: mentioned.slice(0, MENTIONED_PRODUCTS_MAX), hasRelated: false };
  }
  try {
    const refs = await getCartProductRefs(mentioned.map((p) => p.databaseId));
    const withCategories = mentioned.map((product) => ({
      ...product,
      categorySlugs: refs.get(product.databaseId)?.categorySlugs ?? [],
    }));
    const category = withCategories.find((p) => p.categorySlugs.length > 0)
      ?.categorySlugs[0];
    if (!category) return { items: mentioned, hasRelated: false };

    const candidates = (await getProducts({ category, first: 12 })).products.map(
      (product) => ({ ...product, categorySlugs: [category] }),
    );
    // Se pinta el producto tal cual, sin los campos auxiliares del cálculo.
    const bySlug = new Map(mentioned.concat(candidates).map((p) => [p.slug, p]));
    const strip = buildMentionedProductsStrip(
      withCategories.map((p) => ({ ...p, canonicalCategory: category })),
      candidates,
    );
    return {
      items: strip.items.map((item) => bySlug.get(item.slug) ?? item),
      hasRelated: strip.hasRelated,
    };
  } catch {
    return { items: mentioned, hasRelated: false };
  }
}
