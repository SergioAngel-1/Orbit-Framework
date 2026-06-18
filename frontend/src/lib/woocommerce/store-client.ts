import "server-only";
import { WooCommerceError } from "./client";

// ============================================================================
//  Cliente de la WooCommerce Store API (wc/store/v1) — SOLO servidor.
//
//  La Store API gestiona el CARRITO y el CHECKOUT. No usa ck/cs: la sesión del
//  carrito se identifica con un `Cart-Token` (un JWT que emite WooCommerce).
//  El BFF persiste ese token en una cookie httpOnly (lo gestiona el Route
//  Handler) y lo reenvía en cada petición.
// ============================================================================

const STORE_API_URL =
  process.env.WC_STORE_API_URL ?? "http://wordpress:80/wp-json/wc/store/v1";

export interface StoreApiResult<T> {
  data: T;
  /** Token de carrito devuelto por WooCommerce (para persistir en cookie). */
  cartToken: string | null;
}

export interface StoreRequestOptions {
  method?: "GET" | "POST" | "PUT" | "DELETE";
  body?: unknown;
  /** Token de carrito existente (de la cookie). */
  cartToken?: string | null;
  timeoutMs?: number;
}

/**
 * Ejecuta una petición contra la Store API y devuelve los datos junto al
 * `Cart-Token` (nuevo o reutilizado) para que el handler lo persista.
 *
 * @throws WooCommerceError
 */
export async function storeFetch<T>(
  path: string,
  options: StoreRequestOptions = {},
): Promise<StoreApiResult<T>> {
  const { method = "GET", body, cartToken, timeoutMs = 10_000 } = options;

  const headers: Record<string, string> = {
    "Content-Type": "application/json",
    Accept: "application/json",
  };
  if (cartToken) {
    headers["Cart-Token"] = cartToken;
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(`${STORE_API_URL}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: controller.signal,
      cache: "no-store",
    });
    clearTimeout(timer);

    const text = await response.text();
    const data = text ? JSON.parse(text) : null;

    if (!response.ok) {
      const storeError = data as { message?: string; code?: string } | null;
      throw new WooCommerceError(
        storeError?.message ?? `Error de la Store API (${response.status}).`,
        response.status,
        storeError?.code,
      );
    }

    // WooCommerce devuelve el token actualizado en la cabecera Cart-Token.
    const newToken = response.headers.get("Cart-Token") ?? cartToken ?? null;

    return { data: data as T, cartToken: newToken };
  } catch (error) {
    clearTimeout(timer);
    if (error instanceof WooCommerceError) {
      throw error;
    }
    throw new WooCommerceError(
      "No se pudo contactar con la Store API de WooCommerce.",
      502,
      "wc_store_network",
    );
  }
}
