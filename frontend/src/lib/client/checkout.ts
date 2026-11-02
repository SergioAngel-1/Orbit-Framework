"use client";
import { useCallback, useRef, useState } from "react";
import { csrfFetch } from "./csrf";
import { readBff } from "./bff-error";

// ============================================================================
//  Checkout y cobro (lado navegador) sobre `/api/store/checkout` y
//  `/api/payments/create`.
//
//  `usePlaceOrder` guarda lo que es fácil hacer mal en cada vista nueva:
//    · una sola petición a la vez (doble clic, Enter repetido);
//    · la MISMA `Idempotency-Key` en los reintentos de un mismo intento de
//      compra, y una nueva tras un pedido creado. Así un reintento tras un
//      corte de red no crea dos pedidos.
//  La forma del formulario (campos, validación visual, a dónde ir después) es
//  de la vista; esto solo habla con el BFF.
//
//  Recuerda: la prueba de un pago es el webhook verificado, nunca la vuelta
//  del navegador (`docs/FRONTEND_CONNECT.md`, «Pagos»).
// ============================================================================

export interface PlaceOrderInput {
  billing_address: Record<string, string>;
  payment_method: string;
  customer_note?: string;
  shipping_method_ids?: number[];
  identity?: { documentType: string; documentNumber: string };
  terms_accepted?: boolean;
  tracking?: { consent: boolean; locale: string };
  [extra: string]: unknown;
}

export interface PlacedOrder {
  order_id: number;
  status: string;
}

export type PaymentStart =
  | { mode: "redirect"; redirectUrl: string }
  /** Sin cobro en línea aplicable (sin pasarela, sin sesión…): el pedido existe igual. */
  | { mode: "none" };

const DEFAULT_ERROR = "No se pudo crear el pedido.";

export const checkoutApi = {
  async placeOrder(
    input: PlaceOrderInput,
    idempotencyKey: string,
    fallback = DEFAULT_ERROR,
  ): Promise<PlacedOrder> {
    const res = await csrfFetch("/api/store/checkout", {
      method: "POST",
      headers: { "Idempotency-Key": idempotencyKey },
      body: input,
    });
    return readBff<PlacedOrder>(res, fallback);
  },

  /**
   * Inicia el cobro con la pasarela activa. No lanza: cualquier fallo es
   * `{ mode: "none" }`, porque el pedido ya está creado y la vista de «gracias»
   * ofrece reintentar.
   * @param returnUrl absoluta y del propio sitio (si no, el BFF responde 422).
   */
  async startPayment(orderId: number, returnUrl: string): Promise<PaymentStart> {
    try {
      const res = await csrfFetch("/api/payments/create", {
        method: "POST",
        body: { reference: orderId, returnUrl },
      });
      if (!res.ok) return { mode: "none" };
      const data = (await res.json().catch(() => ({}))) as {
        mode?: string;
        redirectUrl?: string;
      };
      return data.mode === "redirect" && data.redirectUrl
        ? { mode: "redirect", redirectUrl: data.redirectUrl }
        : { mode: "none" };
    } catch {
      return { mode: "none" };
    }
  },
};

export interface PlaceOrderState {
  /** Crea el pedido; `null` si ya había uno en curso. Lanza `BffError` si el BFF lo rechaza. */
  placeOrder: (
    input: PlaceOrderInput,
    fallback?: string,
  ) => Promise<PlacedOrder | null>;
  pending: boolean;
}

export function usePlaceOrder(): PlaceOrderState {
  const [pending, setPending] = useState(false);
  const inFlightRef = useRef(false);
  const keyRef = useRef<string | null>(null);

  const placeOrder = useCallback(async (input: PlaceOrderInput, fallback?: string) => {
    if (inFlightRef.current) return null;
    inFlightRef.current = true;
    setPending(true);
    keyRef.current ??= crypto.randomUUID();
    try {
      const order = await checkoutApi.placeOrder(input, keyRef.current, fallback);
      // Pedido creado: el siguiente intento es una compra nueva.
      keyRef.current = null;
      return order;
    } finally {
      inFlightRef.current = false;
      setPending(false);
    }
  }, []);

  return { placeOrder, pending };
}
