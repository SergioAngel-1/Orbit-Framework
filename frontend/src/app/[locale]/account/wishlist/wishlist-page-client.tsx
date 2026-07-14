"use client";
import { useState, useEffect } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { formatPrice } from "@/lib/format";
import type { CatalogProduct } from "@/types/catalog";

interface Props {
  initialIds: number[];
}

export function WishlistPageClient({ initialIds }: Props) {
  const t = useTranslations("wishlist");
  const [products, setProducts] = useState<CatalogProduct[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (initialIds.length === 0) {
      setLoading(false);
      return;
    }
    fetch(`/api/store/products?include=${initialIds.join(",")}`)
      .then((res) => res.json())
      .then((data) => {
        if (data.products) setProducts(data.products);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [initialIds]);

  if (loading) {
    return <p className="text-sm text-gray-500">{t("loading")}</p>;
  }

  if (products.length === 0) {
    return (
      <div className="rounded-xl border border-gray-200 bg-gray-50 p-6 text-center dark:border-gray-800 dark:bg-gray-900">
        <p className="text-gray-600 dark:text-gray-300">{t("empty")}</p>
        <Link
          href="/products"
          className="mt-3 inline-block font-medium text-brand hover:underline"
        >
          {t("browse")}
        </Link>
      </div>
    );
  }

  return (
    <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
      {products.map((product) => (
        <Link key={product.id} href={`/products/${product.slug}`} className="group">
          <article className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
            <div className="relative aspect-square overflow-hidden bg-gray-100 dark:bg-gray-800">
              {product.image?.sourceUrl ? (
                <img
                  src={product.image.sourceUrl}
                  alt={product.image.altText || product.name}
                  className="h-full w-full object-cover transition-transform group-hover:scale-105"
                />
              ) : (
                <div className="flex h-full items-center justify-center text-3xl font-bold text-gray-300">
                  {product.name.charAt(0)}
                </div>
              )}
            </div>
            <div className="p-3">
              <h3 className="text-sm font-semibold leading-snug group-hover:text-brand">
                {product.name}
              </h3>
              <p className="mt-1 font-bold text-sm">{formatPrice(product.price)}</p>
            </div>
          </article>
        </Link>
      ))}
    </div>
  );
}
