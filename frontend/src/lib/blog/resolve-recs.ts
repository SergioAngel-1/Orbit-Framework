import "server-only";
import { getProductsBySlugs } from "@/lib/catalog/products";
import { getPostBySlug } from "@/lib/blog/posts";
import type { RecMarker } from "@/lib/blog/post-content";
import type { CatalogProduct } from "@/types/catalog";
import type { WPPost } from "@/types/wordpress";
import { logReadFailure } from "@/lib/observability/read-failure";

export type ResolvedRec =
  { type: "product"; product: CatalogProduct } | { type: "post"; post: WPPost };

/**
 * Resuelve los marcadores `<!-- hwe-rec -->` de una entrada a su producto o
 * entrada. Un marcador que no resuelve (el producto ya no existe, la entrada es
 * la propia o está sin publicar) queda en `null` y la vista no pinta nada: el
 * artículo no se rompe por una recomendación huérfana.
 *
 * Los productos se piden en UNA consulta; las entradas, una por marcador (son
 * pocas y están en el Data Cache).
 */
export async function resolveRecs(
  markers: RecMarker[],
  opts: { locale: string; currentPostSlug: string },
): Promise<Array<ResolvedRec | null>> {
  const productSlugs = [
    ...new Set(markers.filter((m) => m.type === "product").map((m) => m.slug)),
  ];
  const products = new Map<string, CatalogProduct>();
  if (productSlugs.length > 0) {
    try {
      for (const product of await getProductsBySlugs(productSlugs, opts.locale)) {
        products.set(product.slug, product);
      }
    } catch (error) {
      logReadFailure("blog.recs_products_failed", error, {
        level: "warn",
        message: "Sin catálogo: las recomendaciones de producto no se pintan",
      });
    }
  }

  return Promise.all(
    markers.map(async (marker): Promise<ResolvedRec | null> => {
      if (marker.type === "product") {
        const product = products.get(marker.slug);
        return product ? { type: "product", product } : null;
      }
      if (marker.slug === opts.currentPostSlug) return null;
      try {
        const post = await getPostBySlug(marker.slug, opts.locale);
        return post ? { type: "post", post } : null;
      } catch (error) {
        logReadFailure("blog.recs_post_failed", error, {
          level: "warn",
          context: { slug: marker.slug },
          message: "No se pudo leer la entrada recomendada: no se pinta",
        });
        return null;
      }
    }),
  );
}
