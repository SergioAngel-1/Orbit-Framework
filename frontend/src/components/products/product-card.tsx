"use client";
import Image from "next/image";
import { Link } from "@/i18n/navigation";
import { useTranslations } from "next-intl";
import { AddToCartButton } from "@/components/cart/add-to-cart-button";
import { WishlistButton } from "@/components/products/wishlist-button";
import { formatPrice } from "@/lib/format";
import type { CatalogProduct } from "@/types/catalog";

export interface ProductCardProps {
  product: CatalogProduct;
  /** `config.ecommerce.wishlist_enabled` — el propio caller (Server Component) lo resuelve. */
  wishlistEnabled?: boolean;
}

// Client Component a propósito: lo instancian tanto Server Components
// (ProductGrid) como Client Components (InfiniteProductGrid, con scroll
// infinito) — un Client Component NO puede importar un Server Component
// directamente (rompe el build: "server-only cannot be imported from a
// Client Component module" en cuanto ese Server Component toca algo con
// `server-only`, como getSiteConfig()). Por eso no hace fetch propio: recibe
// `wishlistEnabled` ya resuelto por props.
export function ProductCard({ product, wishlistEnabled = false }: ProductCardProps) {
  const t = useTranslations("products");
  const outOfStock = product.stockStatus === "OUT_OF_STOCK";

  return (
    <article className="group flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
      <div className="relative aspect-square overflow-hidden bg-gray-100 dark:bg-gray-800">
        <Link href={`/products/${product.slug}`} className="block h-full w-full">
          {product.image?.sourceUrl ? (
            <Image
              src={product.image.sourceUrl}
              alt={product.image.altText || product.name}
              fill
              sizes="(max-width: 768px) 50vw, 25vw"
              className="object-cover transition-transform duration-300 group-hover:scale-105"
            />
          ) : (
            <div className="flex h-full items-center justify-center text-3xl font-bold text-gray-300">
              {product.name.charAt(0)}
            </div>
          )}
        </Link>
        {wishlistEnabled && <WishlistButton productId={product.databaseId} />}
        {product.onSale && (
          <span className="absolute left-2 top-2 z-10 rounded-full bg-brand px-2 py-0.5 text-xs font-bold text-white">
            {t("onSale")}
          </span>
        )}
      </div>

      <div className="flex flex-1 flex-col p-4">
        <Link href={`/products/${product.slug}`} className="flex-1">
          <h3 className="font-semibold leading-snug transition-colors group-hover:text-brand">
            {product.name}
          </h3>
        </Link>
        <div className="mt-2 flex items-baseline gap-2">
          <span className="font-bold">{formatPrice(product.price)}</span>
          {product.onSale && product.regularPrice && (
            <span className="text-sm text-gray-400 line-through">
              {formatPrice(product.regularPrice)}
            </span>
          )}
        </div>

        <div className="mt-4">
          {outOfStock ? (
            <span className="text-sm font-medium text-gray-400">{t("outOfStock")}</span>
          ) : (
            <AddToCartButton productId={product.databaseId} />
          )}
        </div>
      </div>
    </article>
  );
}
