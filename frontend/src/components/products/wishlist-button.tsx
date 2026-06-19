"use client";
import { useState, useEffect, useCallback } from "react";
import { wishlistApi } from "@/lib/client/wishlist";

export function WishlistButton({ productId }: { productId: number }) {
  const [isWishlisted, setIsWishlisted] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    wishlistApi.get().then((ids) => {
      setIsWishlisted(ids.includes(productId));
    }).catch(() => {}).finally(() => setLoading(false));
  }, [productId]);

  const toggle = useCallback(async (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (loading) return;
    setLoading(true);
    try {
      await wishlistApi.toggle(productId, isWishlisted);
      setIsWishlisted(!isWishlisted);
    } catch {
      /* ignore */
    } finally {
      setLoading(false);
    }
  }, [productId, isWishlisted, loading]);

  if (loading) {
    return (
      <button disabled className="absolute right-2 top-2 z-10 rounded-full bg-white/80 p-1.5 backdrop-blur dark:bg-black/50">
        <svg className="h-5 w-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
        </svg>
      </button>
    );
  }

  return (
    <button
      onClick={toggle}
      aria-label={isWishlisted ? "Quitar de favoritos" : "Añadir a favoritos"}
      className="absolute right-2 top-2 z-10 rounded-full bg-white/80 p-1.5 backdrop-blur transition-colors hover:bg-white dark:bg-black/50 dark:hover:bg-black/70"
    >
      <svg
        className={`h-5 w-5 ${isWishlisted ? "text-red-500" : "text-gray-400"}`}
        fill={isWishlisted ? "currentColor" : "none"}
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
      </svg>
    </button>
  );
}
