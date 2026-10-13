"use client";
import { useEffect, useRef } from "react";
import type { EcommerceParams } from "@/lib/analytics/ecommerce";
import { trackEvent } from "./track-event";

/**
 * `view_item` (Meta: `ViewContent`) una vez por ficha abierta. La ficha es
 * ISR, así que los parámetros los arma el servidor (`viewItemParams`) y este
 * cliente solo los envía. La dependencia es el id del producto, no el mount:
 * navegar entre dos fichas (p. ej. el cambio Gotero ↔ Spray de Mundo Mascotas)
 * puede reutilizar el componente, y cada ficha es una visita.
 */
export function ViewItemTracker({ params }: { params: EcommerceParams }) {
  const lastTracked = useRef<string | null>(null);
  const productId = params.items[0]?.item_id ?? "";

  useEffect(() => {
    if (!productId || lastTracked.current === productId) return;
    lastTracked.current = productId;
    trackEvent("view_item", params);
  }, [productId, params]);

  return null;
}
