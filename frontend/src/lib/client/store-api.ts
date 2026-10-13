"use client";
import { csrfFetch } from "./csrf";
import { pageLocaleHeaders } from "./page-locale";
import type { StoreCart } from "@/types/woocommerce";
import type { ReorderSummary } from "@/lib/account/reorder";
import type { SiteReview } from "@/lib/account/order-review";
import type { ReviewEligibility } from "@/lib/account/review-eligibility";

// ============================================================================
//  Cliente del carrito (lado navegador). Llama al BFF; nunca a WooCommerce
//  directamente. Las escrituras incluyen el token CSRF automáticamente.
// ============================================================================

/**
 * ¿Tiene la forma mínima de un carrito? Es lo que la UI lee sin comprobar
 * (`items`, `items_count` y `totals`); no valida cada campo de la Store API.
 */
function isStoreCart(value: unknown): value is StoreCart {
  if (value === null || typeof value !== "object") return false;
  const cart = value as Record<string, unknown>;
  return (
    Array.isArray(cart.items) &&
    typeof cart.items_count === "number" &&
    cart.totals !== null &&
    typeof cart.totals === "object"
  );
}

async function parseCart(res: Response): Promise<StoreCart> {
  if (!res.ok) {
    const data = (await res.json().catch(() => ({}))) as { error?: string };
    throw new Error(data.error || `Error ${res.status}`);
  }
  // No se fía de la forma: un 200 con un cuerpo que no es un carrito dejaba
  // `cart = {…}` en el contexto y el cajón reventaba al leer `totals.total_price`
  // (pantalla de error 500 de toda la página). Se trata como "carrito no
  // disponible", que es lo que el contexto ya sabe manejar.
  const body: unknown = await res.json().catch(() => null);
  if (!isStoreCart(body)) throw new Error("La respuesta del carrito no es válida.");
  return body;
}

export const cartApi = {
  async get(): Promise<StoreCart> {
    const res = await fetch("/api/store/cart", {
      credentials: "same-origin",
      headers: pageLocaleHeaders(),
    });
    return parseCart(res);
  },
  async addItem(id: number, quantity = 1): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/cart/items", {
        method: "POST",
        body: { id, quantity },
      }),
    );
  },
  async updateItem(key: string, quantity: number): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/cart/items", {
        method: "PATCH",
        body: { key, quantity },
      }),
    );
  },
  async removeItem(key: string): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/cart/items", { method: "DELETE", body: { key } }),
    );
  },
  async clear(): Promise<StoreCart> {
    return parseCart(await csrfFetch("/api/store/cart", { method: "DELETE" }));
  },
  async applyCoupon(code: string): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/coupons", { method: "POST", body: { code } }),
    );
  },
  async removeCoupon(code: string): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/coupons", { method: "DELETE", body: { code } }),
    );
  },
  async selectShippingRate(packageId: number, rateId: string): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/shipping", {
        method: "PUT",
        body: { package_id: packageId, rate_id: rateId },
      }),
    );
  },

  /**
   * Fija la ciudad/país de destino del carrito (módulo `shipping`): WooCommerce
   * recalcula las tarifas y devuelve el carrito.
   */
  async setShippingDestination(destination: ShippingDestination): Promise<StoreCart> {
    return parseCart(
      await csrfFetch("/api/store/shipping/destination", {
        method: "PUT",
        body: destination,
      }),
    );
  },

  /**
   * «Volver a pedir»: añade al carrito lo que hoy se puede comprar de un pedido
   * anterior. Devuelve el carrito y qué líneas entraron y cuáles no.
   */
  async reorder(orderId: number): Promise<{ cart: StoreCart } & ReorderSummary> {
    const res = await csrfFetch(`/api/store/orders/${orderId}/reorder`, {
      method: "POST",
    });
    if (!res.ok) {
      const data = (await res.json().catch(() => ({}))) as { error?: string };
      throw new Error(data.error || `Error ${res.status}`);
    }
    const body = (await res.json()) as { cart: unknown } & ReorderSummary;
    if (!isStoreCart(body.cart))
      throw new Error("La respuesta del carrito no es válida.");
    return body as { cart: StoreCart } & ReorderSummary;
  },
};

export interface ShippingDestination {
  city: string;
  country: string;
  state?: string;
  postcode?: string;
}

export const reviewApi = {
  /** ¿Puede el cliente reseñar este producto? Nunca lanza: sin respuesta, «no». */
  async eligibility(productId: number): Promise<ReviewEligibility> {
    try {
      const res = await fetch(`/api/store/reviews/${productId}`, {
        credentials: "same-origin",
      });
      if (!res.ok) return { canReview: false, reason: null };
      return (await res.json()) as ReviewEligibility;
    } catch {
      return { canReview: false, reason: null };
    }
  },

  async create(
    productId: number,
    // El nombre y el email de la reseña los fija el servidor desde la cuenta
    // autenticada (ver POST /api/store/reviews/[productId]): no se envían.
    // `sameForOrder`: la misma reseña en los demás productos de ese pedido.
    data: { rating: number; content: string; sameForOrder?: boolean },
  ): Promise<{ id: number; othersReviewed: number }> {
    const res = await csrfFetch(`/api/store/reviews/${productId}`, {
      method: "POST",
      body: data,
    });
    if (!res.ok) {
      const err = (await res.json().catch(() => ({}))) as { error?: string };
      throw new Error(err.error || `Error ${res.status}`);
    }
    const body = (await res.json()) as { id: number; othersReviewed?: number };
    return { id: body.id, othersReviewed: body.othersReviewed ?? 0 };
  },

  /** «Recibí mi pedido»: pasa el pedido de Procesando a Completado. */
  async confirmReceived(orderId: number): Promise<boolean> {
    try {
      const res = await csrfFetch(`/api/store/orders/${orderId}/received`, {
        method: "POST",
      });
      return res.ok;
    } catch {
      return false;
    }
  },

  /**
   * Califica un pedido (reseña del sitio, módulo `site-reviews`) y, con
   * `products`, publica la misma reseña en sus productos. No lanza: devuelve el
   * resultado o el motivo.
   */
  async rateOrder(
    orderId: number,
    data: { rating: number; content: string; products: boolean },
  ): Promise<
    | { ok: true; review: SiteReview; productsReviewed: number }
    | { ok: false; alreadyReviewed: boolean }
  > {
    try {
      const res = await csrfFetch(`/api/store/orders/${orderId}/review`, {
        method: "POST",
        body: data,
      });
      if (!res.ok) return { ok: false, alreadyReviewed: res.status === 409 };
      const body = (await res.json()) as {
        review: SiteReview;
        productsReviewed: number;
      };
      return { ok: true, ...body };
    } catch {
      return { ok: false, alreadyReviewed: false };
    }
  },
};
