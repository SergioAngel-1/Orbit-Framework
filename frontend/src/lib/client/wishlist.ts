"use client";
import { csrfFetch } from "./csrf";

export const wishlistApi = {
  async get(): Promise<number[]> {
    const res = await fetch("/api/store/wishlist", { credentials: "same-origin" });
    if (!res.ok) return [];
    return res.json() as Promise<number[]>;
  },

  async add(productId: number): Promise<void> {
    const res = await csrfFetch("/api/store/wishlist", {
      method: "POST",
      body: { productId },
    });
    if (!res.ok) throw new Error("No se pudo añadir a favoritos");
  },

  async remove(productId: number): Promise<void> {
    const res = await csrfFetch("/api/store/wishlist", {
      method: "DELETE",
      body: { productId },
    });
    if (!res.ok) throw new Error("No se pudo eliminar de favoritos");
  },

  async toggle(productId: number, isWishlisted: boolean): Promise<void> {
    if (isWishlisted) {
      await wishlistApi.remove(productId);
    } else {
      await wishlistApi.add(productId);
    }
  },
};
