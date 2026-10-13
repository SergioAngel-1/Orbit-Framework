"use client";
import { HUBSPOT_DO_NOT_COLLECT } from "@/lib/analytics/hubspot";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { reviewApi } from "@/lib/client/store-api";
import { Button } from "@/components/ui/button";

export function ReviewForm({
  productId,
  reviewerName,
  otherProducts = 0,
}: {
  productId: number;
  /** Nombre con el que se publicará (el del perfil, lo fija el servidor). */
  reviewerName?: string;
  /** Otros productos del mismo pedido aún sin reseñar. */
  otherProducts?: number;
}) {
  const t = useTranslations("products");
  const [pending, setPending] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(e.currentTarget);
    try {
      // El servidor fija el nombre y el email desde la cuenta: no se envían.
      await reviewApi.create(productId, {
        rating: Number(form.get("rating")),
        content: String(form.get("content") ?? ""),
        ...(otherProducts > 0
          ? { sameForOrder: form.get("sameForOrder") === "on" }
          : {}),
      });
      setSubmitted(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error.");
    } finally {
      setPending(false);
    }
  }

  if (submitted) {
    return (
      <p className="text-sm text-green-600 dark:text-green-400">
        {t("reviewSubmitted")}
      </p>
    );
  }

  return (
    <form
      {...HUBSPOT_DO_NOT_COLLECT}
      onSubmit={onSubmit}
      className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800"
    >
      <h3 className="font-semibold text-sm">{t("writeReview")}</h3>
      {error && <p className="text-sm text-red-600">{error}</p>}
      <p className="text-xs text-gray-500">
        {reviewerName
          ? t("reviewPublishingAs", { name: reviewerName })
          : t("reviewAuthorNote")}
      </p>
      <div>
        <label
          htmlFor={`review-rating-${productId}`}
          className="mb-1 block text-sm text-gray-500"
        >
          {t("reviewRating")}
        </label>
        <select
          id={`review-rating-${productId}`}
          name="rating"
          required
          defaultValue="5"
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
        >
          {[5, 4, 3, 2, 1].map((n) => (
            <option key={n} value={n}>
              {n} ★
            </option>
          ))}
        </select>
      </div>
      <textarea
        name="content"
        required
        minLength={10}
        maxLength={3000}
        placeholder={t("reviewPlaceholder")}
        rows={3}
        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
      />
      {otherProducts > 0 && (
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" name="sameForOrder" className="text-brand" />
          {t("reviewSameForOrder", { count: otherProducts })}
        </label>
      )}
      <Button type="submit" disabled={pending}>
        {pending ? "..." : t("reviewSubmit")}
      </Button>
    </form>
  );
}
