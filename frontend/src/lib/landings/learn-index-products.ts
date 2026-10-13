import "server-only";
import { cache } from "react";
import { fetchLocalizedGraphQL } from "@/lib/i18n/fetch-localized";
import { pickTranslated } from "@/lib/i18n/translation";
import { localizedUrlSlug } from "@/lib/i18n/url-slug";
import { getProductBoundLandingSlugs } from "@/lib/landings/product-bound-landings";
import { productLearnHref } from "@/lib/landings/product-learn-href";
import { LEARN_INDEX_PRODUCTS_QUERY } from "@/lib/landings/queries";
import { logReadFailure } from "@/lib/observability/read-failure";

// ============================================================================
//  Las landings de PRODUCTO para el índice del área educativa (`/learn`): el
//  nombre, la imagen, la ruta de su landing y la categoría con la que se agrupan
//  (`groupLearnProducts`).
//
//  La categoría es la primera del producto en WooGraphQL: el índice solo la usa
//  para agrupar, así que no hace falta la «canónica» que fija una URL.
//  Degrada a vacío y queda en el log: un índice sin guías es mejor que una ruta
//  caída.
// ============================================================================

export interface LearnIndexProduct {
  slug: string;
  name: string;
  /** Ruta interna de la landing del producto (sin prefijo de idioma). */
  href: string;
  image: { sourceUrl: string; altText: string } | null;
  category: { slug: string; name: string };
}

interface RawNode extends Record<string, unknown> {
  slug: string;
  name: string | null;
  image: { sourceUrl: string; altText: string | null } | null;
  productCategories: { nodes: { slug: string; name: string }[] } | null;
}

const BATCH = 50;
/** Categoría de los productos sin ninguna. */
const UNCATEGORIZED = { slug: "", name: "" };

export const getLearnIndexProducts = cache(
  async (locale: string): Promise<LearnIndexProduct[]> => {
    const slugs = [...(await getProductBoundLandingSlugs())];
    const out: LearnIndexProduct[] = [];
    for (let i = 0; i < slugs.length; i += BATCH) {
      const batch = slugs.slice(i, i + BATCH);
      try {
        const data = await fetchLocalizedGraphQL<{
          products: { nodes: RawNode[] } | null;
        }>(LEARN_INDEX_PRODUCTS_QUERY, locale, {
          variables: { slugs: batch },
          revalidate: 3600,
          tags: ["products", "landings"],
        });
        for (const node of data.products?.nodes ?? []) {
          out.push({
            slug: node.slug,
            name: pickTranslated(node, "name", locale) ?? node.slug,
            href: productLearnHref({
              slug: node.slug,
              urlSlug: localizedUrlSlug(node, locale),
            }),
            image: node.image
              ? { sourceUrl: node.image.sourceUrl, altText: node.image.altText ?? "" }
              : null,
            category: node.productCategories?.nodes[0] ?? UNCATEGORIZED,
          });
        }
      } catch (error) {
        logReadFailure("landings.learn_index_failed", error, {
          context: { count: batch.length },
          message: "No se pudieron leer los productos del índice del área educativa",
        });
      }
    }
    return out;
  },
);
