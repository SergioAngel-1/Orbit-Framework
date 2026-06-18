"use client";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";
import { useCart } from "@/components/cart/cart-context";

interface CheckoutResult {
  order_id: number;
  status: string;
}

export function CheckoutForm() {
  const { cart, refresh } = useCart();
  const tCheckout = useTranslations("checkout");
  const tForm = useTranslations("form");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<CheckoutResult | null>(null);

  const FIELDS: { name: string; label: string; type?: string; required?: boolean }[] =
    [
      { name: "first_name", label: tForm("firstName"), required: true },
      { name: "last_name", label: tForm("lastName"), required: true },
      { name: "address_1", label: tForm("address"), required: true },
      { name: "city", label: tForm("city"), required: true },
      { name: "postcode", label: tForm("postcode"), required: true },
      { name: "country", label: tForm("country"), required: true },
      { name: "email", label: tForm("email"), type: "email", required: true },
      { name: "phone", label: tForm("phone") },
    ];

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(e.currentTarget);

    const billing_address = Object.fromEntries(
      FIELDS.map((f) => [f.name, String(form.get(f.name) ?? "")]),
    );

    try {
      const res = await csrfFetch("/api/store/checkout", {
        method: "POST",
        headers: { "Idempotency-Key": crypto.randomUUID() },
        body: {
          billing_address,
          payment_method: "cod",
          customer_note: "",
        },
      });
      const data = (await res.json().catch(() => ({}))) as
        | CheckoutResult
        | { error?: string };
      if (!res.ok) {
        throw new Error(
          ("error" in data && data.error) || tCheckout("emptyCart"),
        );
      }
      setResult(data as CheckoutResult);
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error.");
    } finally {
      setPending(false);
    }
  }

  if (result) {
    return (
      <div className="rounded-xl border border-green-200 bg-green-50 p-8 text-center dark:border-green-900 dark:bg-green-950">
        <h2 className="text-xl font-bold text-green-800 dark:text-green-200">
          {tCheckout("success")}
        </h2>
        <p className="mt-2 text-green-700 dark:text-green-300">
          {tCheckout("orderNumber")} <strong>#{result.order_id}</strong> · {tCheckout("status")}: {result.status}
        </p>
      </div>
    );
  }

  const empty = !cart || cart.items.length === 0;

  return (
    <form onSubmit={onSubmit} className="space-y-4">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      {empty && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
          {tCheckout("emptyCart")}
        </p>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {FIELDS.map((f) => (
          <div key={f.name}>
            <label className="mb-1 block text-sm font-medium">{f.label}</label>
            <input
              name={f.name}
              type={f.type ?? "text"}
              required={f.required}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
            />
          </div>
        ))}
      </div>

      <button
        type="submit"
        disabled={pending || empty}
        className="w-full rounded-lg bg-brand py-3 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
      >
        {pending ? tCheckout("processing") : tCheckout("submit")}
      </button>
    </form>
  );
}
