"use client";
import Image from "next/image";
import { Link } from "@/i18n/navigation";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { formatPrice } from "@/lib/format";
import { cn } from "@/lib/utils";
import type { CatalogProduct } from "@/types/catalog";

export interface ProductCardHorizontalProps {
  product: CatalogProduct;
  /** Destino del enlace. Por defecto `/products/{slug}`. */
  href?: string;
  /** Nombre de categoría a mostrar sobre el título (opcional). */
  category?: string;
  /** Valoración media 0–5 (opcional). */
  rating?: number;
  /** Número de reseñas (opcional). */
  reviews?: number;
  /** Marca visual de selección (uso en resultados de búsqueda navegables por teclado). */
  selected?: boolean;
  onMouseEnter?: () => void;
  onClick?: () => void;
  saleLabel?: string;
  loading?: boolean;
  className?: string;
}

export function ProductCardHorizontal({
  product,
  href,
  category,
  rating,
  reviews = 0,
  selected = false,
  onMouseEnter,
  onClick,
  saleLabel = "Oferta",
  loading = false,
  className,
}: ProductCardHorizontalProps) {
  if (loading) {
    return (
      <div className={cn("flex items-center gap-4 rounded-xl p-3", className)}>
        <Skeleton variant="block" className="h-20 w-20 shrink-0 rounded-lg" />
        <div className="min-w-0 flex-1">
          <Skeleton variant="block" height="h-2" width="w-16" className="mb-2" />
          <Skeleton variant="block" height="h-3" width="w-3/4" className="mb-2" />
          <Skeleton variant="block" height="h-3" width="w-20" />
        </div>
      </div>
    );
  }

  return (
    <Link
      href={href ?? `/products/${product.slug}`}
      onMouseEnter={onMouseEnter}
      onClick={onClick}
      className={cn(
        "flex w-full items-center gap-4 rounded-xl border p-3 text-left transition-all duration-150",
        selected
          ? "border-brand/30 bg-brand/10"
          : "border-transparent hover:border-gray-200 hover:bg-surface dark:hover:border-gray-700 dark:hover:bg-gray-800",
        className,
      )}
    >
      <div className="relative h-20 w-20 shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
        {product.image?.sourceUrl ? (
          <Image
            src={product.image.sourceUrl}
            alt={product.image.altText || product.name}
            fill
            sizes="80px"
            className="object-cover"
          />
        ) : (
          <div className="flex h-full items-center justify-center text-xl font-bold text-gray-300">
            {product.name.charAt(0)}
          </div>
        )}
        {product.onSale && (
          <Badge
            color="brand"
            size="sm"
            className="absolute left-1 top-1 uppercase tracking-wide"
          >
            {saleLabel}
          </Badge>
        )}
      </div>

      <div className="min-w-0 flex-1">
        {category && (
          <p className="mb-0.5 font-sans text-[9px] uppercase tracking-[0.15em] text-[--foreground]/40">
            {category}
          </p>
        )}
        <h4 className="mb-1 truncate font-heading text-sm leading-tight text-[--foreground]">
          {product.name}
        </h4>
        {rating !== undefined && (
          <div className="mb-1 flex items-center gap-2">
            <StarRating rating={rating} />
            {reviews > 0 && (
              <span className="font-sans text-[10px] text-[--foreground]/40">
                ({reviews})
              </span>
            )}
          </div>
        )}
        <div className="flex items-baseline gap-2">
          <span className="font-heading text-base leading-none text-brand">
            {formatPrice(product.price)}
          </span>
          {product.onSale && product.regularPrice && (
            <span className="font-sans text-[11px] leading-none text-[--foreground]/40 line-through">
              {formatPrice(product.regularPrice)}
            </span>
          )}
        </div>
      </div>

      <span
        className={cn(
          "shrink-0 transition-all duration-150",
          selected ? "text-brand" : "text-[--foreground]/20",
        )}
      >
        <svg
          width="16"
          height="16"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden
        >
          <path d="M9 5l7 7-7 7" />
        </svg>
      </span>
    </Link>
  );
}

function StarRating({ rating }: { rating: number }) {
  return (
    <div className="flex items-center gap-0.5" aria-label={`${rating} de 5`}>
      {[1, 2, 3, 4, 5].map((star) => (
        <svg
          key={star}
          width="12"
          height="12"
          viewBox="0 0 24 24"
          fill="currentColor"
          aria-hidden
          className={cn(
            star <= Math.floor(rating)
              ? "text-accent"
              : star <= rating
                ? "text-accent/50"
                : "text-gray-300 dark:text-gray-600",
          )}
        >
          <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
        </svg>
      ))}
    </div>
  );
}
