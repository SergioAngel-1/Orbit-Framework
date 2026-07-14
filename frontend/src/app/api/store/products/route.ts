import { NextResponse } from "next/server";
import { getProducts } from "@/lib/catalog/products";
import { wcFetch } from "@/lib/woocommerce/client";
import { logger } from "@/lib/observability/logger";
import type { CatalogProduct } from "@/types/catalog";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const { searchParams } = new URL(request.url);
  const includeParam = searchParams.get("include");

  // Support fetching by specific IDs (for wishlist page)
  if (includeParam) {
    try {
      const ids = includeParam
        .split(",")
        .map(Number)
        .filter((n) => !isNaN(n));
      const raw = await wcFetch<Record<string, unknown>[]>("/products", {
        query: { include: ids.join(","), per_page: 100 },
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

  try {
    const result = await getProducts({
      search: searchParams.get("search") || undefined,
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
