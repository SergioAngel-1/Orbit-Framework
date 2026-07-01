"use client";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";
import { useCart } from "@/components/cart/cart-context";
import { Button } from "@/components/ui/button";
import { formatStoreAmount } from "@/lib/format";

interface CheckoutResult {
  order_id: number;
  status: string;
}

export interface CheckoutFormProps {
  /** `config.ecommerce.coupons_enabled` — oculta la sección de cupón si es `false`. */
  couponsEnabled?: boolean;
}

export function CheckoutForm({ couponsEnabled = false }: CheckoutFormProps) {
  const { cart, loading, refresh, applyCoupon, removeCoupon, selectShippingRate } = useCart();
  const t = useTranslations();
  const tCheckout = t.raw("checkout") as unknown as Record<string, string>;
  const tCart = t.raw("cart") as unknown as Record<string, string>;
  const tForm = t.raw("form") as unknown as Record<string, string>;
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<CheckoutResult | null>(null);
  const [couponCode, setCouponCode] = useState("");

  const FIELDS: { name: string; label: string; type?: string; required?: boolean }[] = [
    { name: "first_name", label: tForm.firstName, required: true },
    { name: "last_name", label: tForm.lastName, required: true },
    { name: "address_1", label: tForm.address, required: true },
    { name: "city", label: tForm.city, required: true },
    { name: "postcode", label: tForm.postcode, required: true },
    { name: "country", label: tForm.country, required: true },
    { name: "email", label: tForm.email, type: "email", required: true },
    { name: "phone", label: tForm.phone },
  ];

  /**
   * Inicia el cobro del pedido con la pasarela activa (capa agnóstica).
   * @returns true si redirige al checkout alojado (la página se abandona).
   */
  async function startPayment(orderId: number): Promise<boolean> {
    try {
      const res = await csrfFetch("/api/payments/create", {
        method: "POST",
        body: { reference: orderId },
      });
      if (!res.ok) return false; // sin sesión / pasarela no aplicable → fallback
      const data = (await res.json().catch(() => ({}))) as {
        mode?: string;
        redirectUrl?: string;
      };
      if (data.mode === "redirect" && data.redirectUrl) {
        window.location.href = data.redirectUrl;
        return true;
      }
      return false;
    } catch {
      return false;
    }
  }

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
        throw new Error(("error" in data && data.error) || tCheckout.emptyCart);
      }

      const order = data as CheckoutResult;
      await refresh();

      // Capa de pagos agnóstica: inicia el cobro con la pasarela activa. Si
      // devuelve un checkout alojado, redirige; si no hay pasarela online
      // (o sin sesión), se queda con el pedido creado (flujo contra reembolso).
      const redirected = await startPayment(order.order_id);
      if (!redirected) {
        setResult(order);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error.");
    } finally {
      setPending(false);
    }
  }

  if (result) {
    return (
      <div className="rounded-xl border border-green-200 bg-green-50 p-8 text-center dark:border-green-900 dark:bg-green-950">
        <h2 className="text-xl font-bold text-green-800 dark:text-green-200">{tCheckout.success}</h2>
        <p className="mt-2 text-green-700 dark:text-green-300">
          {tCheckout.orderNumber} <strong>#{result.order_id}</strong> · {tCheckout.status}: {result.status}
        </p>
      </div>
    );
  }

  const empty = !cart || cart.items.length === 0;

  return (
    <form onSubmit={onSubmit} className="space-y-6">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">{error}</p>
      )}
      {empty && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">{tCheckout.emptyCart}</p>
      )}

      {/* Cupón */}
      {couponsEnabled && (
        <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <h3 className="mb-2 text-sm font-semibold">{tCart.couponCode}</h3>
          <div className="flex gap-2">
            <input
              type="text"
              value={couponCode}
              onChange={(e) => setCouponCode(e.target.value)}
              placeholder={tCart.couponCode}
              className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
            />
            <Button variant="outline" size="sm" disabled={loading || !couponCode.trim()} onClick={() => { applyCoupon(couponCode.trim()); setCouponCode(""); }}>
              {tCart.applyCoupon}
            </Button>
          </div>
          {cart?.coupons.map((c) => (
            <div key={c.code} className="mt-2 flex items-center justify-between text-sm">
              <span className="text-green-600 dark:text-green-400">{c.code}</span>
              <button type="button" disabled={loading} onClick={() => removeCoupon(c.code)} className="text-xs text-gray-400 hover:text-red-600">{tCart.removeCoupon}</button>
            </div>
          ))}
        </section>
      )}

      {/* Selección de envío */}
      {cart && cart.needs_shipping && cart.shipping_rates.length > 0 && (
        <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <h3 className="mb-2 text-sm font-semibold">{tCheckout.shippingMethod}</h3>
          {cart.shipping_rates.map((pkg) => (
            <div key={pkg.package_id}>
              <p className="mb-1 text-xs text-gray-500">{pkg.name}</p>
              {pkg.shipping_rates.map((rate) => (
                <label key={rate.rate_id} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-900">
                  <input
                    type="radio"
                    name={`shipping_${pkg.package_id}`}
                    defaultChecked={rate.selected}
                    onChange={() => selectShippingRate(pkg.package_id, rate.rate_id)}
                    className="text-brand"
                  />
                  <span className="text-sm">{rate.name}</span>
                  <span className="ml-auto text-sm font-medium">
                    {rate.price === "0" ? "Gratis" : formatStoreAmount(rate.price, rate.currency_minor_unit, rate.currency_code)}
                  </span>
                </label>
              ))}
            </div>
          ))}
        </section>
      )}

      {/* Dirección */}
      <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
        <h3 className="mb-3 text-sm font-semibold">{tCheckout.shippingAddress}</h3>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          {FIELDS.map((f) => (
            <div key={f.name}>
              <label className="mb-1 block text-sm font-medium">{f.label}</label>
              <input name={f.name} type={f.type ?? "text"} required={f.required} className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" />
            </div>
          ))}
        </div>
      </section>

      {/* Resumen */}
      <section className="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
        <h3 className="mb-3 text-sm font-semibold">{tCart.total}</h3>
        <div className="space-y-1 text-sm">
          <div className="flex justify-between"><span className="text-gray-500">{tCart.subtotal}</span><span>{cart && formatStoreAmount(cart.totals.total_items, cart.totals.currency_minor_unit, cart.totals.currency_code)}</span></div>
          {cart && cart.totals.total_discount !== "0" && (
            <div className="flex justify-between"><span className="text-gray-500">{tCart.discount}</span><span className="text-green-600">-{formatStoreAmount(cart.totals.total_discount, cart.totals.currency_minor_unit, cart.totals.currency_code)}</span></div>
          )}
          {cart && cart.totals.total_shipping !== "0" && (
            <div className="flex justify-between"><span className="text-gray-500">{tCart.shipping}</span><span>{formatStoreAmount(cart.totals.total_shipping, cart.totals.currency_minor_unit, cart.totals.currency_code)}</span></div>
          )}
          <div className="flex justify-between border-t border-gray-200 pt-2 font-semibold dark:border-gray-700"><span>{tCart.total}</span><span>{cart && formatStoreAmount(cart.totals.total_price, cart.totals.currency_minor_unit, cart.totals.currency_code)}</span></div>
        </div>
      </section>

      <button type="submit" disabled={pending || empty} className="w-full rounded-lg bg-brand py-3 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50">
        {pending ? tCheckout.processing : tCheckout.submit}
      </button>
    </form>
  );
}
