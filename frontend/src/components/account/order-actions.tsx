"use client";
import { HUBSPOT_DO_NOT_COLLECT } from "@/lib/analytics/hubspot";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "@/i18n/navigation";
import { useCart } from "@/components/cart/cart-context";
import { cartApi, reviewApi } from "@/lib/client/store-api";
import type { ReorderSkipped } from "@/lib/account/reorder";
import type { OrderReviewMode } from "@/lib/account/order-review";

/**
 * Acciones de un pedido en la cuenta: volver a pedir, «Recibí mi pedido» y
 * calificarlo. La vista (servidor) decide qué se ofrece con el estado del
 * pedido (`orderReviewState`) y con el módulo `site-reviews`; este componente
 * solo ejecuta. Todo pasa por el BFF, que vuelve a comprobar la propiedad.
 */
export function OrderActions({
  orderId,
  reorder,
  review,
}: {
  orderId: number;
  /** Ofrecer «Volver a pedir». */
  reorder: boolean;
  /**
   * `null`: nada que hacer con la calificación (sin módulo, o sin estado que
   * la admita). `productCount`: productos del pedido aún sin reseñar.
   */
  review: { mode: OrderReviewMode; productCount: number } | null;
}) {
  const t = useTranslations("account.orderActions");
  const router = useRouter();
  const { refresh, openDrawer } = useCart();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [skipped, setSkipped] = useState<ReorderSkipped[] | null>(null);
  const [rating, setRating] = useState(5);
  const [content, setContent] = useState("");
  const [products, setProducts] = useState(false);
  const [rateOpen, setRateOpen] = useState(false);

  async function onReorder() {
    if (busy) return;
    setBusy(true);
    setError(null);
    setSkipped(null);
    try {
      const result = await cartApi.reorder(orderId);
      await refresh();
      setSkipped(result.skipped);
      if (result.added.length > 0) openDrawer();
      else if (result.skipped.length === 0) setError(t("nothingToReorder"));
    } catch (e) {
      setError(e instanceof Error ? e.message : t("genericError"));
    } finally {
      setBusy(false);
    }
  }

  async function onReceived() {
    if (busy) return;
    setBusy(true);
    setError(null);
    const ok = await reviewApi.confirmReceived(orderId);
    setBusy(false);
    if (ok) router.refresh();
    else setError(t("genericError"));
  }

  async function onRate(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(null);
    const result = await reviewApi.rateOrder(orderId, {
      rating,
      content: content.trim(),
      products: products && (review?.productCount ?? 0) > 0,
    });
    setBusy(false);
    if (result.ok) {
      router.refresh();
      return;
    }
    setError(result.alreadyReviewed ? t("alreadyRated") : t("genericError"));
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        {reorder && (
          <button
            type="button"
            onClick={() => void onReorder()}
            disabled={busy}
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium transition-colors hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:hover:bg-gray-900"
          >
            {t("reorder")}
          </button>
        )}
        {review?.mode === "receive" && (
          <button
            type="button"
            onClick={() => void onReceived()}
            disabled={busy}
            className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
          >
            {t("received")}
          </button>
        )}
        {review?.mode === "rate" && !rateOpen && (
          <button
            type="button"
            onClick={() => setRateOpen(true)}
            className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-dark"
          >
            {t("rate")}
          </button>
        )}
      </div>

      {skipped && skipped.length > 0 && (
        <div
          role="status"
          className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200"
        >
          <p className="font-medium">{t("skippedTitle")}</p>
          <ul className="mt-1 list-disc pl-5">
            {skipped.map((s) => (
              <li key={s.name}>
                {s.name}: {t(`skipped.${s.reason}`)}
              </li>
            ))}
          </ul>
        </div>
      )}

      {error && (
        <p
          role="alert"
          className="rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300"
        >
          {error}
        </p>
      )}

      {review?.mode === "rate" && rateOpen && (
        <form
          {...HUBSPOT_DO_NOT_COLLECT}
          onSubmit={(e) => void onRate(e)}
          className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800"
        >
          <div>
            <label htmlFor="order-rating" className="mb-1 block text-sm text-gray-500">
              {t("rating")}
            </label>
            <select
              id="order-rating"
              value={rating}
              onChange={(e) => setRating(Number(e.target.value))}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
            >
              {[5, 4, 3, 2, 1].map((n) => (
                <option key={n} value={n}>
                  {n} ★
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="order-comment" className="mb-1 block text-sm text-gray-500">
              {t("comment")}
            </label>
            <textarea
              id="order-comment"
              required
              minLength={10}
              maxLength={3000}
              rows={3}
              value={content}
              onChange={(e) => setContent(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
            />
          </div>
          {review.productCount > 0 && (
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={products}
                onChange={(e) => setProducts(e.target.checked)}
                className="text-brand"
              />
              {t("alsoProducts", { count: review.productCount })}
            </label>
          )}
          <button
            type="submit"
            disabled={busy}
            className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
          >
            {t("submitRating")}
          </button>
        </form>
      )}
    </div>
  );
}
