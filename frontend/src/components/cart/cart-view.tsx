"use client";
import Image from "next/image";
import { Link } from "@/i18n/navigation";
import { useTranslations } from "next-intl";
import { useCart } from "./cart-context";
import { formatStoreAmount } from "@/lib/format";

export function CartView() {
  const { cart, loading, error, updateItem, removeItem } = useCart();
  const tCart = useTranslations("cart");

  if (!cart) {
    return (
      <p className="text-gray-500">{loading ? tCart("loading") : tCart("empty")}</p>
    );
  }

  if (cart.items.length === 0) {
    return (
      <div className="rounded-xl border border-gray-200 bg-gray-50 p-8 text-center dark:border-gray-800 dark:bg-gray-900">
        <p className="text-gray-600 dark:text-gray-300">{tCart("emptyDetailed")}</p>
        <Link
          href="/products"
          className="mt-4 inline-block font-medium text-brand hover:underline"
        >
          {tCart("viewProducts")}
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}

      <ul className="divide-y divide-gray-200 dark:divide-gray-800">
        {cart.items.map((item) => (
          <li key={item.key} className="flex gap-4 py-4">
            <div className="relative h-20 w-20 shrink-0 overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800">
              {item.images?.[0]?.thumbnail && (
                <Image
                  src={item.images[0].thumbnail}
                  alt={item.images[0].alt || item.name}
                  fill
                  sizes="80px"
                  className="object-cover"
                />
              )}
            </div>

            <div className="flex flex-1 flex-col">
              <span className="font-medium">{item.name}</span>
              <span className="text-sm text-gray-500">
                {formatStoreAmount(
                  item.prices.price,
                  item.prices.currency_minor_unit,
                  item.prices.currency_code,
                )}
              </span>

              <div className="mt-2 flex items-center gap-3">
                <label className="text-sm text-gray-500">
                  {tCart("quantity")}
                  <input
                    type="number"
                    min={1}
                    defaultValue={item.quantity}
                    disabled={loading}
                    onChange={(e) => {
                      const q = Number(e.target.value);
                      if (q >= 1) void updateItem(item.key, q);
                    }}
                    className="ml-2 w-16 rounded border border-gray-300 px-2 py-1 dark:border-gray-700 dark:bg-gray-900"
                  />
                </label>
                <button
                  type="button"
                  onClick={() => void removeItem(item.key)}
                  disabled={loading}
                  className="text-sm text-red-600 hover:underline disabled:opacity-50"
                >
                  {tCart("remove")}
                </button>
              </div>
            </div>

            <div className="text-right font-semibold">
              {formatStoreAmount(
                item.totals.line_total,
                cart.totals.currency_minor_unit,
                cart.totals.currency_code,
              )}
            </div>
          </li>
        ))}
      </ul>

      <div className="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-800">
        <span className="text-lg font-bold">{tCart("total")}</span>
        <span className="text-lg font-bold">
          {formatStoreAmount(
            cart.totals.total_price,
            cart.totals.currency_minor_unit,
            cart.totals.currency_code,
          )}
        </span>
      </div>

      <Link
        href="/checkout"
        className="block rounded-lg bg-brand py-3 text-center font-semibold text-white transition-colors hover:bg-brand-dark"
      >
        {tCart("checkout")}
      </Link>
    </div>
  );
}
