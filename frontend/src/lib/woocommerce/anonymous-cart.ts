import "server-only";
import { WooCommerceError } from "./client";
import { storeFetch } from "./store-client";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

// ============================================================================
//  Carrito vacío de un visitante SIN carrito y SIN sesión.
//
//  `GET /api/store/cart` lo pide el `CartProvider` a todo el que abre la web,
//  también a los robots. Cada una de esas lecturas abría una sesión nueva de
//  WooCommerce (la Store API emite un `Cart-Token` por visitante) y gastaba un
//  worker de PHP: con diez visitas simultáneas la tienda entera se degradaba.
//
//  La forma del carrito vacío (moneda, totales, direcciones, medios de pago)
//  solo la conoce la Store API, así que se lee UNA vez sin token y se guarda en
//  memoria del proceso, con TTL y single-flight (una sola lectura en vuelo).
//  No se guarda el token que devuelve esa lectura: un visitante sin carrito no
//  recibe `Set-Cookie` hasta que añade su primer producto (`POST /cart/items`).
//
//  Con la copia caducada se sirve AL INSTANTE y se refresca en segundo plano
//  (stale-while-revalidate): nadie espera a WordPress solo para pintar la
//  cabecera. Si el refresco falla, la copia sigue sirviéndose y el siguiente
//  intento se aplaza `FAILURE_BACKOFF_MS`: con WordPress saturado o caído no se
//  encola una lectura (ni su timeout de 10 s) por cada visitante. Sin copia no
//  hay nada que servir: la primera lectura se espera y su error sube tal cual
//  (nunca se inventa un carrito).
// ============================================================================

/** Minutos: la moneda y la forma del carrito vacío cambian muy de vez en cuando. */
const TTL_MS = 5 * 60_000;

/** Tras un refresco fallido con copia, no se reintenta hasta pasada esta ventana. */
const FAILURE_BACKOFF_MS = 30_000;

let cached: { cart: StoreCart; at: number } | null = null;
let inflight: Promise<StoreCart> | null = null;
/** Antes de este instante no se reintenta una lectura que ya falló con copia. */
let retryAt = 0;

/** Solo para tests: olvida la copia, la lectura en vuelo y el backoff. */
export function resetAnonymousCartCache(): void {
  cached = null;
  inflight = null;
  retryAt = 0;
}

/**
 * ¿Es un carrito vacío de verdad? La Store API contesta al vaciado con una
 * LISTA y, sin token, siempre debería devolver un carrito sin líneas. Cualquier
 * otra cosa (una lista, un carrito con líneas) NO se cachea ni se sirve: podría
 * ser el carrito de otra persona y mostrarlo sería peor que fallar.
 */
function isEmptyStoreCart(data: unknown): data is StoreCart {
  if (typeof data !== "object" || data === null || Array.isArray(data)) return false;
  const cart = data as StoreCart;
  return (
    Array.isArray(cart.items) &&
    cart.items.length === 0 &&
    Number(cart.items_count ?? 0) === 0
  );
}

async function readEmptyCart(): Promise<StoreCart> {
  try {
    const { data } = await storeFetch<StoreCart>("/cart");
    if (!isEmptyStoreCart(data)) {
      throw new WooCommerceError(
        "La Store API no devolvió un carrito vacío sin sesión.",
        502,
        "wc_store_invalid_cart",
      );
    }
    cached = { cart: data, at: Date.now() };
    retryAt = 0;
    return data;
  } catch (error) {
    if (cached) {
      retryAt = Date.now() + FAILURE_BACKOFF_MS;
      logger.warn(
        {
          event: "cart.empty.stale",
          backoffMs: FAILURE_BACKOFF_MS,
          err: error instanceof Error ? error.message : error,
        },
        "La Store API falló; se sirve la última copia del carrito vacío",
      );
      return cached.cart;
    }
    throw error;
  }
}

/** Una sola lectura en vuelo: las llamadas concurrentes comparten la promesa. */
function refresh(): Promise<StoreCart> {
  if (inflight) return inflight;
  const pending = readEmptyCart();
  inflight = pending;
  // Sin `finally`: su promesa derivada rechazaría sin manejador cuando la
  // lectura falla y el llamador ya la está tratando.
  const clear = () => {
    if (inflight === pending) inflight = null;
  };
  pending.then(clear, clear);
  return pending;
}

/**
 * Refresco que nadie espera. Con copia, `readEmptyCart` nunca rechaza (sirve la
 * copia), pero se deja un manejador por si el día de mañana cambia.
 */
function refreshInBackground(now: number): void {
  if (inflight || now < retryAt) return;
  void refresh().catch((error) => {
    logger.error(
      {
        event: "cart.empty.background_error",
        err: error instanceof Error ? error.message : error,
      },
      "Fallo inesperado al refrescar el carrito vacío en segundo plano",
    );
  });
}

/**
 * Carrito vacío canónico de un visitante anónimo. Sin copia, la primera lectura
 * se espera (una sola por single-flight) y su error sube tal cual. Con copia
 * vigente no se toca WordPress; con copia caducada se devuelve al instante y se
 * refresca en segundo plano, con backoff tras un fallo.
 */
export function getAnonymousEmptyCart(): Promise<StoreCart> {
  const now = Date.now();
  if (cached) {
    if (now - cached.at < TTL_MS) return Promise.resolve(cached.cart);
    refreshInBackground(now);
    return Promise.resolve(cached.cart);
  }
  return refresh();
}
