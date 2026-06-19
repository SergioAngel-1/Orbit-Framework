"use client";
import { useEffect, useState } from "react";
import Image from "next/image";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { useCart } from "./cart-context";
import { Button } from "@/components/ui/button";
import { Spinner } from "@/components/ui/spinner";
import { formatStoreAmount } from "@/lib/format";
import { cn } from "@/lib/utils";

// ============================================================================
//  Cajón lateral del carrito (mini-cart / cart drawer).
//  Se abre desde CartIndicator. Incluye incremento/decremento de cantidad,
//  quitar artículos, total y botones de navegación a checkout/carrito.
// ============================================================================

export function CartDrawer() {
  const { cart, loading, error, updateItem, removeItem, applyCoupon, removeCoupon, drawerOpen, closeDrawer } = useCart();
  const tCart = useTranslations("cart");
  const [couponCode, setCouponCode] = useState("");

  // Cerrar con Escape
  useEffect(() => {
    if (!drawerOpen) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === "Escape") closeDrawer();
    };
    document.addEventListener("keydown", handler);
    return () => document.removeEventListener("keydown", handler);
  }, [drawerOpen, closeDrawer]);

  // Bloquear scroll del body
  useEffect(() => {
    document.body.style.overflow = drawerOpen ? "hidden" : "";
    return () => { document.body.style.overflow = ""; };
  }, [drawerOpen]);

  const total = cart
    ? formatStoreAmount(
        cart.totals.total_price,
        cart.totals.currency_minor_unit,
        cart.totals.currency_code,
      )
    : null;

  return (
    <>
      {/* Backdrop */}
      <div
        onClick={closeDrawer}
        aria-hidden="true"
        className={cn(
          "fixed inset-0 z-40 bg-black/40 backdrop-blur-sm transition-opacity duration-300",
          drawerOpen ? "opacity-100" : "pointer-events-none opacity-0",
        )}
      />

      {/* Panel deslizante */}
      <aside
        role="dialog"
        aria-modal="true"
        aria-label={tCart("title")}
        className={cn(
          "fixed right-0 top-0 z-50 flex h-full w-full max-w-sm flex-col",
          "bg-white shadow-2xl dark:bg-gray-900",
          "transition-transform duration-300 ease-in-out",
          drawerOpen ? "translate-x-0" : "translate-x-full",
        )}
      >
        {/* Header */}
        <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800">
          <h2 className="font-semibold">{tCart("title")}</h2>
          <Button
            variant="ghost"
            size="icon"
            onClick={closeDrawer}
            aria-label="Cerrar carrito"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </Button>
        </div>

        {/* Cuerpo con scroll */}
        <div className="flex-1 overflow-y-auto px-5 py-4">
          {error && (
            <p className="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
              {error}
            </p>
          )}

          {loading && !cart && (
            <div className="flex items-center justify-center py-16">
              <Spinner size="lg" />
            </div>
          )}

          {cart && cart.items.length === 0 && (
            <div className="py-12 text-center">
              <p className="text-gray-500 dark:text-gray-400">{tCart("emptyDetailed")}</p>
              <Link
                href="/products"
                onClick={closeDrawer}
                className="mt-4 inline-flex items-center justify-center rounded-lg border border-brand px-4 py-2 text-sm font-semibold text-brand transition-colors hover:bg-brand/10"
              >
                {tCart("viewProducts")}
              </Link>
            </div>
          )}

          {cart && cart.items.length > 0 && (
            <ul className="divide-y divide-gray-100 dark:divide-gray-800">
              {cart.items.map((item) => (
                <li key={item.key} className="flex gap-3 py-4">
                  {/* Miniatura */}
                  <div className="relative h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-800">
                    {item.images?.[0]?.thumbnail && (
                      <Image
                        src={item.images[0].thumbnail}
                        alt={item.images[0].alt || item.name}
                        fill
                        sizes="64px"
                        className="object-cover"
                      />
                    )}
                  </div>

                  {/* Detalles */}
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium leading-snug">{item.name}</p>
                    <p className="mt-0.5 text-xs text-gray-500">
                      {formatStoreAmount(
                        item.prices.price,
                        item.prices.currency_minor_unit,
                        item.prices.currency_code,
                      )}
                    </p>

                    {/* Controles de cantidad */}
                    <div className="mt-2 flex items-center gap-3">
                      <div className="flex items-center rounded-lg border border-gray-200 dark:border-gray-700">
                        <button
                          type="button"
                          disabled={loading || item.quantity <= 1}
                          onClick={() => void updateItem(item.key, item.quantity - 1)}
                          className="flex h-7 w-7 items-center justify-center text-gray-500 transition-colors hover:text-brand disabled:opacity-30"
                          aria-label="Reducir cantidad"
                        >
                          −
                        </button>
                        <span className="min-w-[1.5rem] text-center text-sm font-medium">
                          {item.quantity}
                        </span>
                        <button
                          type="button"
                          disabled={loading}
                          onClick={() => void updateItem(item.key, item.quantity + 1)}
                          className="flex h-7 w-7 items-center justify-center text-gray-500 transition-colors hover:text-brand disabled:opacity-30"
                          aria-label="Aumentar cantidad"
                        >
                          +
                        </button>
                      </div>

                      <button
                        type="button"
                        disabled={loading}
                        onClick={() => void removeItem(item.key)}
                        className="text-xs text-gray-400 transition-colors hover:text-red-600 disabled:opacity-30"
                      >
                        {tCart("remove")}
                      </button>
                    </div>
                  </div>

                  {/* Total línea */}
                  <p className="text-sm font-semibold">
                    {formatStoreAmount(
                      item.totals.line_total,
                      cart.totals.currency_minor_unit,
                      cart.totals.currency_code,
                    )}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>

        {/* Cupones */}
        {cart && cart.items.length > 0 && (
          <div className="border-t border-gray-200 px-5 py-4 dark:border-gray-800">
            <div className="flex gap-2">
              <input
                type="text"
                value={couponCode}
                onChange={(e) => setCouponCode(e.target.value)}
                placeholder={tCart("couponCode")}
                className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
              />
              <Button
                variant="outline"
                size="sm"
                disabled={loading || !couponCode.trim()}
                onClick={() => { applyCoupon(couponCode.trim()); setCouponCode(""); }}
              >
                {tCart("applyCoupon")}
              </Button>
            </div>
            {cart.coupons.length > 0 && (
              <ul className="mt-2 space-y-1">
                {cart.coupons.map((c) => (
                  <li key={c.code} className="flex items-center justify-between text-sm">
                    <span className="text-green-600 dark:text-green-400">
                      {c.label} ({c.code})
                    </span>
                    <button
                      type="button"
                      disabled={loading}
                      onClick={() => removeCoupon(c.code)}
                      className="text-xs text-gray-400 transition-colors hover:text-red-600"
                    >
                      {tCart("removeCoupon")}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}

        {/* Footer */}
        {cart && cart.items.length > 0 && (
          <div className="border-t border-gray-200 px-5 py-5 dark:border-gray-800">
            <div className="mb-2 flex items-center justify-between text-sm">
              <span className="text-gray-500">{tCart("subtotal")}</span>
              <span>{formatStoreAmount(cart.totals.total_items, cart.totals.currency_minor_unit, cart.totals.currency_code)}</span>
            </div>
            {cart.totals.total_discount !== "0" && (
              <div className="mb-2 flex items-center justify-between text-sm">
                <span className="text-gray-500">{tCart("discount")}</span>
                <span className="text-green-600">-{formatStoreAmount(cart.totals.total_discount, cart.totals.currency_minor_unit, cart.totals.currency_code)}</span>
              </div>
            )}
            <div className="mb-4 flex items-center justify-between">
              <span className="font-medium">{tCart("total")}</span>
              <span className="text-xl font-bold">{total}</span>
            </div>

            <Link
              href="/checkout"
              onClick={closeDrawer}
              className="flex w-full items-center justify-center rounded-lg bg-brand py-3 font-semibold text-white transition-colors hover:bg-brand-dark"
            >
              {tCart("checkout")}
            </Link>
            <Link
              href="/cart"
              onClick={closeDrawer}
              className="mt-2 flex w-full items-center justify-center rounded-lg py-2 text-sm font-medium text-brand transition-colors hover:bg-brand/8"
            >
              {tCart("viewCart")}
            </Link>
          </div>
        )}
      </aside>
    </>
  );
}
