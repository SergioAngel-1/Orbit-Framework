import "server-only";
import { cache } from "react";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";
import { getProductsByIds } from "./products";
import type { CatalogProduct } from "@/types/catalog";

// ============================================================================
//  Más vendidos, leído del mu-plugin `hwe-best-sellers.php`
//  (`GET /wp-json/hwe/v1/best-sellers`, solo con el secreto interno).
//
//  El ranking se calcula en WordPress desde las líneas de los pedidos pagados
//  (no desde `total_sales`; el porqué, en la cabecera del mu-plugin). Una sola
//  petición cacheada por hora (tag `products`) y memoizada por request.
//
//  Fail-soft: sin ranking (WordPress caído, tienda sin ventas) se devuelve una
//  lista vacía y la UI no pinta la sección; nunca rompe la página.
// ============================================================================

const BEST_SELLERS_REVALIDATE = 3600;

export interface BestSellerEntry {
  id: number;
  slug: string;
  qty: number;
}

/** Normaliza la respuesta del endpoint (defensivo: es JSON externo). */
export function parseBestSellerRanking(payload: unknown): BestSellerEntry[] {
  const items = (payload as { items?: unknown } | null)?.items;
  if (!Array.isArray(items)) return [];
  const seen = new Set<number>();
  const ranking: BestSellerEntry[] = [];
  for (const row of items) {
    const id = Number((row as { id?: unknown })?.id);
    const slug = (row as { slug?: unknown })?.slug;
    const qty = Number((row as { qty?: unknown })?.qty);
    if (!Number.isInteger(id) || id <= 0 || seen.has(id)) continue;
    if (typeof slug !== "string" || slug === "" || !Number.isFinite(qty) || qty <= 0)
      continue;
    seen.add(id);
    ranking.push({ id, slug, qty });
  }
  return ranking;
}

/** Ranking completo, de más a menos unidades vendidas. */
export const getBestSellerRanking = cache(async (): Promise<BestSellerEntry[]> => {
  try {
    const res = await wpFetch("/wp-json/hwe/v1/best-sellers", {
      next: { revalidate: BEST_SELLERS_REVALIDATE, tags: ["products"] },
    });
    if (!res.ok) {
      logger.warn({ event: "catalog.best_sellers.http", status: res.status });
      return [];
    }
    return parseBestSellerRanking(await res.json());
  } catch (error) {
    logger.warn({
      event: "catalog.best_sellers.error",
      err: error instanceof Error ? error.message : String(error),
    });
    return [];
  }
});

/** Los `limit` productos más vendidos, publicados y en orden de ranking. */
export async function getBestSellingProducts(
  limit = 4,
  locale?: string,
): Promise<CatalogProduct[]> {
  const ranking = await getBestSellerRanking();
  if (ranking.length === 0) return [];
  try {
    return await getProductsByIds(
      ranking.slice(0, limit).map((entry) => entry.id),
      locale,
    );
  } catch (error) {
    logger.warn({
      event: "catalog.best_sellers.products_error",
      err: error instanceof Error ? error.message : String(error),
    });
    return [];
  }
}
