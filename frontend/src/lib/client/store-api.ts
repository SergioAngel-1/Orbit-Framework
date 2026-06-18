"use client";
import { csrfFetch } from "./csrf";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Cliente del carrito (lado navegador). Llama al BFF; nunca a WooCommerce
//  directamente. Las escrituras incluyen el token CSRF automáticamente.
// ============================================================================

async function parseCart(res: Response): Promise<StoreCart> {
  if (!res.ok) {
    const data = (await res.json().catch(() => ({}))) as { error?: string };
    throw new Error(data.error || `Error ${res.status}`);
  }
  return res.json() as Promise<StoreCart>;
}

export const cartApi = {
  async get(): Promise<StoreCart> {
    const res = await fetch("/api/store/cart", { credentials: "same-origin" });
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
      await csrfFetch("/api/store/cart/items", {
        method: "DELETE",
        body: { key },
      }),
    );
  },
  async clear(): Promise<StoreCart> {
    return parseCart(await csrfFetch("/api/store/cart", { method: "DELETE" }));
  },
};
