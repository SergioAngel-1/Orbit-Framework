"use client";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { reviewApi } from "@/lib/client/store-api";
import { Button } from "@/components/ui/button";

export function ReviewForm({ productId }: { productId: number }) {
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
      await reviewApi.create(productId, {
        rating: Number(form.get("rating")),
        content: String(form.get("content") ?? ""),
        name: String(form.get("name") ?? ""),
        email: String(form.get("email") ?? ""),
      });
      setSubmitted(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error.");
    } finally {
      setPending(false);
    }
  }

  if (submitted) {
    return <p className="text-sm text-green-600 dark:text-green-400">{t("reviewSubmitted")}</p>;
  }

  return (
    <form onSubmit={onSubmit} className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
      <h3 className="font-semibold text-sm">{t("writeReview")}</h3>
      {error && <p className="text-sm text-red-600">{error}</p>}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <input
          name="name"
          required
          placeholder={t("reviewName")}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
        />
        <input
          name="email"
          type="email"
          required
          placeholder={t("reviewEmail")}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
        />
      </div>
      <div>
        <label className="mb-1 block text-sm text-gray-500">{t("reviewRating")}</label>
        <select
          name="rating"
          required
          defaultValue="5"
          className="rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
        >
          {[5, 4, 3, 2, 1].map((n) => (
            <option key={n} value={n}>{n} ★</option>
          ))}
        </select>
      </div>
      <textarea
        name="content"
        required
        placeholder={t("reviewPlaceholder")}
        rows={3}
        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
      />
      <Button type="submit" disabled={pending}>
        {pending ? "..." : t("reviewSubmit")}
      </Button>
    </form>
  );
}
