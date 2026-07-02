"use client";
import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { ProductCard } from "./product-card";
import type { CatalogProduct } from "@/types/catalog";

interface Props {
  initialProducts: CatalogProduct[];
  initialPageInfo: { hasNextPage: boolean; endCursor: string | null };
  filters: Record<string, string | undefined>;
  /** `config.ecommerce.wishlist_enabled`, resuelto por el Server Component padre. */
  wishlistEnabled?: boolean;
}

export function InfiniteProductGrid({ initialProducts, initialPageInfo, filters, wishlistEnabled = false }: Props) {
  const t = useTranslations("products");
  const [products, setProducts] = useState(initialProducts);
  const [pageInfo, setPageInfo] = useState(initialPageInfo);
  const [loading, setLoading] = useState(false);
  const sentinelRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setProducts(initialProducts);
    setPageInfo(initialPageInfo);
  }, [initialProducts, initialPageInfo]);

  useEffect(() => {
    if (!pageInfo.hasNextPage || loading) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting && pageInfo.hasNextPage && !loading) {
          loadMore();
        }
      },
      { rootMargin: "200px" },
    );

    const sentinel = sentinelRef.current;
    if (sentinel) observer.observe(sentinel);
    return () => { if (sentinel) observer.unobserve(sentinel); };
  }, [pageInfo, loading, filters]);

  async function loadMore() {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (filters.search) params.set("search", filters.search);
      if (filters.category) params.set("category", filters.category);
      if (filters.minPrice) params.set("minPrice", filters.minPrice);
      if (filters.maxPrice) params.set("maxPrice", filters.maxPrice);
      if (filters.sort) params.set("sort", filters.sort);
      if (pageInfo.endCursor) params.set("after", pageInfo.endCursor);
      params.set("first", "12");

      const res = await fetch(`/api/store/products?${params}`);
      if (!res.ok) throw new Error();
      const data = await res.json() as { products: CatalogProduct[]; pageInfo: typeof pageInfo };
      setProducts((prev) => [...prev, ...data.products]);
      setPageInfo(data.pageInfo);
    } catch {
      /* silent fail */
    } finally {
      setLoading(false);
    }
  }

  if (products.length === 0) {
    return (
      <p className="rounded-xl border border-gray-200 bg-gray-50 p-6 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
        {t("notFound")}
      </p>
    );
  }

  return (
    <>
      <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} wishlistEnabled={wishlistEnabled} />
        ))}
      </div>

      {pageInfo.hasNextPage && (
        <div ref={sentinelRef} className="mt-10 flex justify-center">
          {loading ? (
            <div className="flex items-center gap-2 text-sm text-gray-400">
              <svg className="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              {t("loadMore")}
            </div>
          ) : (
            <div className="h-8" />
          )}
        </div>
      )}
    </>
  );
}
