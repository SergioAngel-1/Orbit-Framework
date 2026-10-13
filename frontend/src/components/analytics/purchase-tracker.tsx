"use client";
import { useEffect, useRef } from "react";
import type { EcommerceParams } from "@/lib/analytics/ecommerce";
import { trackEvent } from "./track-event";

// ============================================================================
//  Dispara el evento `purchase` una sola vez al confirmarse el pago, desde la
//  página de retorno del checkout (Server Component → este cliente). Es no-op
//  sin consentimiento/proveedor. La fuente de verdad del pago sigue siendo el
//  webhook firmado; esto solo registra la conversión para analítica.
//
//  Dedup en dos capas: el `useRef` cubre el re-render dentro del mismo mount
//  (p. ej. doble invocación de React StrictMode). Como ese ref se reinicia al
//  recargar/remontar `/checkout/gracias?ref=X`, se añade una marca en
//  `localStorage` por order id. Es `localStorage` y no `sessionStorage` porque
//  la segunda es POR PESTAÑA: abrir el enlace de gracias en otra (el del correo,
//  el historial) volvía a contar la compra.
// ============================================================================

/** Prefijo de la clave de `localStorage` que marca un pedido ya registrado. */
export const PURCHASE_TRACKED_KEY_PREFIX = "hwe_purchase_tracked_";

export function purchaseTrackedKey(orderId: string | number): string {
  return `${PURCHASE_TRACKED_KEY_PREFIX}${orderId}`;
}

/**
 * `params` lo arma el servidor con `purchaseParams` (lib/analytics/ecommerce),
 * desde el pedido de wc/v3: la página de gracias ya lo tiene cargado.
 */
export function PurchaseTracker({ params }: { params: EcommerceParams }) {
  const fired = useRef(false);
  const orderId = params.transaction_id ?? "";

  useEffect(() => {
    if (fired.current || !orderId) return;

    const key = purchaseTrackedKey(orderId);
    try {
      if (window.localStorage.getItem(key)) {
        fired.current = true;
        return;
      }
      window.localStorage.setItem(key, "1");
    } catch {
      // El almacenamiento puede fallar (Safari con cookies bloqueadas,
      // cuotas…): no bloquea el tracking, solo se pierde la protección contra
      // recargas. El `eventID`/`transaction_id` de la compra deduplica igual
      // en Meta y en GA4.
    }

    fired.current = true;
    trackEvent("purchase", params);
  }, [orderId, params]);

  return null;
}
