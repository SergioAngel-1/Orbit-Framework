"use client";
import { useEffect } from "react";
import { useRouter } from "@/i18n/navigation";

// ============================================================================
//  Vuelve a pintar la página de «gracias» cuando la pasarela confirma el pago.
//
//  La pasarela devuelve al comprador a `/checkout/return` a la vez que manda
//  su webhook, y no hay orden garantizado entre los dos: con PSE lo normal es
//  llegar ANTES de que el pedido pase a pagado. La página, que se pinta en el
//  servidor, enseñaba entonces «pendiente» para siempre, y como la compra solo
//  se mide con el pedido pagado, esa visita no la registraba nunca.
//
//  Mientras el pedido siga pendiente se consulta su estado
//  (`/api/payments/return`, la misma autorización que la página) con esperas
//  crecientes; cuando cambia, `router.refresh()` vuelve a pedir la página al
//  servidor, que ya la pinta pagada y con su `PurchaseTracker`.
// ============================================================================

/** Esperas entre consultas (ms): unos 2 minutos en total, y se deja de mirar. */
export const PAYMENT_STATUS_POLL_DELAYS = [
  2_000, 3_000, 4_000, 5_000, 5_000, 10_000, 10_000, 15_000, 15_000, 30_000, 30_000,
];

export function PaymentStatusWatcher({
  orderId,
  status,
}: {
  orderId: string;
  /** Estado con el que se pintó la página: cualquier otro merece repintarla. */
  status: string;
}) {
  const router = useRouter();

  useEffect(() => {
    let cancelled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    const poll = async (attempt: number) => {
      try {
        const res = await fetch(
          `/api/payments/return?ref=${encodeURIComponent(orderId)}`,
          {
            credentials: "same-origin",
            cache: "no-store",
          },
        );
        if (cancelled) return;
        if (res.ok) {
          const data = (await res.json()) as { status?: string; paid?: boolean };
          if (cancelled) return;
          if (data.paid || (data.status && data.status !== status)) {
            router.refresh();
            return;
          }
        }
      } catch {
        // Red caída: se reintenta en la siguiente vuelta.
      }
      if (cancelled) return;
      schedule(attempt + 1);
    };

    const schedule = (attempt: number) => {
      const delay = PAYMENT_STATUS_POLL_DELAYS[attempt];
      if (delay === undefined) return;
      timer = setTimeout(() => void poll(attempt), delay);
    };

    schedule(0);
    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [orderId, status, router]);

  return null;
}
