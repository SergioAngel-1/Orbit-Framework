import { routing } from "@/i18n/routing";
import { NextResponse } from "next/server";
import { getSiteConfig } from "@/lib/config";
import { requireFlag } from "@/lib/api/flags";
import { getProducts } from "@/lib/catalog/products";
import { wcFetch } from "@/lib/woocommerce/client";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";
import { getClientIp } from "@/lib/http/request-ip";
import type { CatalogProduct } from "@/types/catalog";

export const dynamic = "force-dynamic";

/** Tope de ids por petición: el `per_page` máximo de WooCommerce. */
const MAX_INCLUDE_IDS = 100;

/** Ids de producto válidos de `?include=1,2,3`: enteros positivos, sin duplicados, acotados. */
function parseProductIds(raw: string): number[] {
  const ids = new Set<number>();
  for (const part of raw.split(",")) {
    if (!/^\d+$/.test(part.trim())) continue;
    const id = Number(part);
    if (Number.isSafeInteger(id) && id > 0) ids.add(id);
    if (ids.size >= MAX_INCLUDE_IDS) break;
  }
  return [...ids];
}

export async function GET(request: Request) {
  const { searchParams } = new URL(request.url);
  const includeParam = searchParams.get("include");
  const config = await getSiteConfig();

  // `?include=` es la lista de deseos; `?search=` es la búsqueda. Apagadas en el
  // Control Center, esas formas de la ruta no existen (el listado normal sí).
  if (includeParam) {
    const disabled = requireFlag(config, "wishlist_enabled");
    if (disabled) return disabled;
  }
  if (searchParams.get("search")?.trim()) {
    const disabled = requireFlag(config, "search_enabled");
    if (disabled) return disabled;
  }

  // Support fetching by specific IDs (for wishlist page)
  if (includeParam) {
    try {
      const ids = parseProductIds(includeParam);
      // Sin ningún id válido no se consulta: un `include` vacío NO filtra nada para
      // WooCommerce y devolvería los primeros 100 productos.
      if (ids.length === 0) {
        return NextResponse.json({
          products: [],
          pageInfo: { hasNextPage: false, endCursor: null },
        });
      }
      // `status: "publish"` es imprescindible: esta llamada usa las credenciales del
      // SERVIDOR y WooCommerce devuelve por defecto productos de cualquier estado
      // (borrador, pendiente, privado). El endpoint es público: sin él, cualquiera
      // podía leer un producto sin publicar pidiéndolo por id.
      const raw = await wcFetch<Record<string, unknown>[]>("/products", {
        query: { include: ids.join(","), per_page: MAX_INCLUDE_IDS, status: "publish" },
      });
      const products: CatalogProduct[] = raw.map((p) => {
        const images = Array.isArray(p.images)
          ? (p.images as { src?: string; alt?: string }[])
          : [];
        return {
          id: String(p.id),
          databaseId: Number(p.id),
          name: String(p.name ?? ""),
          slug: String(p.slug ?? ""),
          type: String(p.type ?? "simple"),
          onSale: Boolean(p.on_sale),
          price: p.price != null ? String(p.price) : null,
          regularPrice: p.regular_price != null ? String(p.regular_price) : null,
          salePrice: p.sale_price != null ? String(p.sale_price) : null,
          stockStatus: String(p.stock_status ?? "IN_STOCK"),
          shortDescription: String(p.short_description ?? ""),
          image: images[0]
            ? {
                sourceUrl: String(images[0].src ?? ""),
                altText: String(images[0].alt ?? ""),
              }
            : null,
        };
      });
      return NextResponse.json({
        products,
        pageInfo: { hasNextPage: false, endCursor: null },
      });
    } catch (error) {
      logger.error({
        event: "api.products.include_error",
        err: error instanceof Error ? error.message : error,
      });
      return NextResponse.json({
        products: [],
        pageInfo: { hasNextPage: false, endCursor: null },
      });
    }
  }

  // La búsqueda es la parte cara y abusable de este endpoint público (un recorrido
  // por el catálogo por cada pulsación); el listado normal es cacheable y no se
  // limita. Fail-open si Redis no responde, como todo rate-limit no estricto.
  const searchQuery = searchParams.get("search")?.trim() ?? "";
  if (searchQuery) {
    const rl = await rateLimit(`products-search:${getClientIp(request)}`, 20, 60);
    if (!rl.success) {
      logger.warn(
        { event: "api.products.search_rate_limited" },
        "Rate-limit en la búsqueda de productos",
      );
      return NextResponse.json(
        { error: "Demasiadas búsquedas." },
        { status: 429, headers: { "Retry-After": String(rl.retryAfter) } },
      );
    }
  }

  // Idioma de los textos y las URLs: solo uno de los del sitio (un valor ajeno
  // cae al idioma por defecto y nunca llega a la query).
  const requestedLocale = searchParams.get("locale") ?? "";
  const locale = (routing.locales as readonly string[]).includes(requestedLocale)
    ? requestedLocale
    : routing.defaultLocale;

  try {
    const result = await getProducts({
      locale,
      search: searchQuery || undefined,
      after: searchParams.get("after") || undefined,
      category: searchParams.get("category") || undefined,
      minPrice: searchParams.get("minPrice") || undefined,
      maxPrice: searchParams.get("maxPrice") || undefined,
      sort: searchParams.get("sort") || undefined,
      first: Number(searchParams.get("first")) || 12,
    });

    return NextResponse.json(result, {
      headers: { "Cache-Control": "public, max-age=300, s-maxage=300" },
    });
  } catch (error) {
    logger.error({
      event: "api.products.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json(
      { error: "No se pudieron cargar productos." },
      { status: 502 },
    );
  }
}
