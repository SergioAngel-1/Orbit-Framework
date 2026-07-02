import { getTranslations } from "next-intl/server";
import { ProductCard } from "./product-card";
import { getSiteConfig } from "@/lib/config";
import type { CatalogProduct } from "@/types/catalog";

export async function ProductGrid({ products }: { products: CatalogProduct[] }) {
  const [t, config] = await Promise.all([getTranslations("products"), getSiteConfig()]);
  if (products.length === 0) {
    return (
      <p className="rounded-xl border border-gray-200 bg-gray-50 p-6 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
        {t("notFound")}
      </p>
    );
  }

  return (
    <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
      {products.map((product) => (
        <ProductCard
          key={product.id}
          product={product}
          wishlistEnabled={config.ecommerce.wishlist_enabled}
        />
      ))}
    </div>
  );
}
