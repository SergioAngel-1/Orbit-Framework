"use client";
import { useEffect, useRef } from "react";
import { trackEvent } from "./analytics-provider";

// ============================================================================
//  Dispara el evento `purchase` una sola vez al confirmarse el pago, desde la
//  página de retorno del checkout (Server Component → este cliente). Es no-op
//  sin consentimiento/proveedor. La fuente de verdad del pago sigue siendo el
//  webhook firmado; esto solo registra la conversión para analítica.
// ============================================================================

export function PurchaseTracker({
  orderId,
  amount,
  currency,
}: {
  orderId: string | number;
  amount: number;
  currency: string;
}) {
  const fired = useRef(false);

  useEffect(() => {
    if (fired.current) return;
    fired.current = true;
    trackEvent(
      "purchase",
      { order_id: orderId },
      currency && Number.isFinite(amount) ? { currency, amount } : undefined,
    );
  }, [orderId, amount, currency]);

  return null;
}
