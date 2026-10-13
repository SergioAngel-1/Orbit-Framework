"use client";
import { useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";
import { checkoutReturnUrl } from "@/lib/checkout/return-url";

/**
 * «Pagar ahora» de un pedido en línea que sigue sin pagar: pide a la pasarela
 * activa un cobro nuevo (`/api/payments/create`) y lleva al cliente a ella. La
 * autorización la pone el BFF (sesión o credencial de invitado); el cobro solo
 * se confirma por el webhook verificado, nunca por este botón.
 */
export function PayOrderButton({ orderId }: { orderId: number }) {
  const locale = useLocale();
  const t = useTranslations("checkout");
  const [pending, setPending] = useState(false);
  const [failed, setFailed] = useState(false);

  async function pay() {
    if (pending) return;
    setPending(true);
    setFailed(false);
    try {
      const res = await csrfFetch("/api/payments/create", {
        method: "POST",
        body: {
          reference: orderId,
          returnUrl: checkoutReturnUrl(
            window.location.origin,
            locale as Parameters<typeof checkoutReturnUrl>[1],
            orderId,
          ),
        },
      });
      const data = (await res.json().catch(() => ({}))) as {
        mode?: string;
        redirectUrl?: string;
      };
      if (res.ok && data.mode === "redirect" && data.redirectUrl) {
        window.location.href = data.redirectUrl;
        return;
      }
      setFailed(true);
    } catch {
      setFailed(true);
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="space-y-3">
      {failed && (
        <p
          role="alert"
          className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
          {t("paymentNotStarted")}
        </p>
      )}
      <button
        type="button"
        onClick={() => void pay()}
        disabled={pending}
        className="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
      >
        {pending ? t("processing") : t("return.payNow")}
      </button>
    </div>
  );
}
