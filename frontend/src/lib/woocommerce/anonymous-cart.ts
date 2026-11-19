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
//  Si WordPress falla y hay copia, se sirve la copia: mejor un carrito vacío de
//  hace unos minutos que un 502 en la cabecera de la tienda. Sin copia, el
//  error sube tal cual y el handler responde como siempre (nunca se inventa un
//  carrito).
// ============================================================================

/** Minutos: la moneda y la forma del carrito vacío cambian muy de vez en cuando. */
const TTL_MS = 5 * 60_000;

let cached: { cart: StoreCart; at: number } | null = null;
let inflight: Promise<StoreCart> | null = null;

/** Solo para tests: olvida la copia y la lectura en vuelo. */
export function resetAnonymousCartCache(): void {
  cached = null;
  inflight = null;
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
    return data;
  } catch (error) {
    if (cached) {
      logger.warn(
        {
          event: "cart.empty.stale",
          err: error instanceof Error ? error.message : error,
        },
        "La Store API falló; se sirve la última copia del carrito vacío",
      );
      return cached.cart;
    }
    throw error;
  }
}

/**
 * Carrito vacío canónico de un visitante anónimo. La primera llamada (o la
 * primera tras caducar el TTL) lee la Store API sin token; el resto la copia en
 * memoria. Las llamadas concurrentes comparten la misma lectura en vuelo.
 */
export function getAnonymousEmptyCart(): Promise<StoreCart> {
  if (cached && Date.now() - cached.at < TTL_MS) {
    return Promise.resolve(cached.cart);
  }
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
