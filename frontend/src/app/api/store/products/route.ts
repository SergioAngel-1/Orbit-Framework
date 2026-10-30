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

/** Mayor id de producto admitido: `exclude` viaja como `[Int]` de GraphQL, de 32 bits con signo. */
const MAX_PRODUCT_ID = 2_147_483_647;

/**
 * Presupuesto por IP de TODO el GET (listado, filtros, paginación, búsqueda y
 * lista de ids). Cada URL distinta es una consulta nueva a WordPress (la clave
 * del Data Cache incluye las variables), y el BFF llama con el secreto interno,
 * así que WordPress no le aplica su propio límite: el único tope posible está
 * aquí. Fail-open si Redis no responde, como todo rate-limit no estricto.
 */
const LIST_LIMIT = 60;
const LIST_WINDOW_SECONDS = 60;

/** Tamaño de página por defecto y tope que se entrega a WPGraphQL. */
const DEFAULT_FIRST = 12;
const MAX_FIRST = 24;

/** Un cursor de WPGraphQL es base64 (`arrayconnection:NN`); nada más entra en la consulta. */
const CURSOR_RE = /^[A-Za-z0-9+/=_-]{1,200}$/;

/**
 * Slug de categoría de WordPress: letras de cualquier alfabeto, números, `_`,
 * `%` (los slugs no latinos guardan el percent-encoding), `.` y `-`. Descarta
 * controles, barras y cualquier cosa que no sea un slug.
 */
const CATEGORY_RE = /^[\p{L}\p{N}_%.\-]{1,200}$/u;

/** Precio del filtro: número finito y no negativo, con un techo absurdo. */
const MAX_PRICE = 1_000_000_000;

function categoryParam(raw: string | null): string | undefined {
  const value = raw?.trim();
  return value && CATEGORY_RE.test(value) ? value : undefined;
}

function priceParam(raw: string | null): string | undefined {
  const value = raw?.trim();
  if (!value) return undefined;
  const price = Number(value);
  return Number.isFinite(price) && price >= 0 && price <= MAX_PRICE ? value : undefined;
}

function afterParam(raw: string | null): string | undefined {
  const value = raw?.trim();
  return value && CURSOR_RE.test(value) ? value : undefined;
}

/** Ids de producto válidos de `?include=1,2,3`: enteros positivos, sin duplicados, acotados. */
function parseProductIds(raw: string): number[] {
  const ids = new Set<number>();
  for (const part of raw.split(",")) {
    if (!/^\d+$/.test(part.trim())) continue;
    const id = Number(part);
    if (Number.isInteger(id) && id > 0 && id <= MAX_PRODUCT_ID) ids.add(id);
    if (ids.size >= MAX_INCLUDE_IDS) break;
  }
  return [...ids];
}

/**
 * Ids de `?exclude=1,2,3`, ordenados: el mismo conjunto en otro orden comparte entrada en el
 * Data Cache de Next (su clave incluye las variables). Solo RESTA de lo que ya es público, así
 * que no descubre nada que el listado no enseñe, y va bajo el presupuesto común por IP.
 */
function excludeParam(raw: string | null): number[] {
  return raw ? parseProductIds(raw).sort((a, b) => a - b) : [];
}

export async function GET(request: Request) {
  // Antes de CUALQUIER trabajo contra WordPress (config, catálogo o wc/v3):
  // todo este GET consume el mismo presupuesto por IP, aunque el atacante varíe
  // `category`, `first`, `after` o no use `search`.
  const rl = await rateLimit(
    `products-list:${getClientIp(request)}`,
    LIST_LIMIT,
    LIST_WINDOW_SECONDS,
  );
  if (!rl.success) {
    logger.warn(
      { event: "api.products.rate_limited" },
      "Rate-limit en el listado de productos",
    );
    return NextResponse.json(
      { error: "Demasiadas peticiones." },
      { status: 429, headers: { "Retry-After": String(rl.retryAfter) } },
    );
  }

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

  // La búsqueda, igual que el resto del GET, ya pasó por el presupuesto común de
  // arriba: no lleva un límite aparte ni una rama que el atacante pueda esquivar.
  const searchQuery = searchParams.get("search")?.trim() ?? "";

  // Idioma de los textos y las URLs: solo uno de los del sitio (un valor ajeno
  // cae al idioma por defecto y nunca llega a la query).
  const requestedLocale = searchParams.get("locale") ?? "";
  const locale = (routing.locales as readonly string[]).includes(requestedLocale)
    ? requestedLocale
    : routing.defaultLocale;

  // Todo lo que entra en la consulta se valida/acota aquí: un parámetro mal
  // formado se descarta antes de construirla, y `first` nunca supera el tope.
  const requestedFirst = Number(searchParams.get("first"));
  const first =
    Number.isInteger(requestedFirst) && requestedFirst > 0
      ? Math.min(requestedFirst, MAX_FIRST)
      : DEFAULT_FIRST;

  const exclude = excludeParam(searchParams.get("exclude"));

  try {
    const result = await getProducts({
      locale,
      search: searchQuery || undefined,
      after: afterParam(searchParams.get("after")),
      category: categoryParam(searchParams.get("category")),
      minPrice: priceParam(searchParams.get("minPrice")),
      maxPrice: priceParam(searchParams.get("maxPrice")),
      sort: searchParams.get("sort") || undefined,
      first,
      ...(exclude.length > 0 ? { exclude } : {}),
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
