import crypto from "node:crypto";
import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import {
  getCartRequestAuth,
  applyCartStockAdjustments,
} from "@/lib/woocommerce/cart-session";
import { completeStoreCart } from "@/lib/woocommerce/complete-cart";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { rateLimit } from "@/lib/security/rate-limit";
import { getClientIp } from "@/lib/http/request-ip";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * Lecturas del carrito por ventana, por IP y carrito. Una persona lo pide una vez
 * por carga de página (el `CartProvider` vive en el layout y no se remonta al
 * navegar), así que 30 por minuto solo lo alcanza un cliente en bucle.
 */
const CART_READ_LIMIT = 30;
const CART_READ_WINDOW_SECONDS = 60;

/**
 * Clave del rate-limit de lectura. Lleva el carrito además de la IP para no
 * castigar a compradores tras CGNAT; el token va hasheado porque es la credencial
 * del carrito y no debe acabar en claro en una clave de Redis.
 */
async function cartReadLimitKey(request: Request): Promise<string> {
  const token = await readCartToken();
  const cart = token
    ? crypto.createHash("sha256").update(token).digest("hex").slice(0, 16)
    : "anon";
  return `cart_read:${getClientIp(request)}:${cart}`;
}

/** GET /api/store/cart — devuelve el carrito actual. */
export async function GET(request: Request) {
  // Esta lectura NO pasa por `guardMutation` (no es una escritura) y cada una acaba
  // en la Store API de WordPress, sin caché: PHP pesado que ocupa uno de los pocos
  // workers de Apache. Un solo navegador en bucle (~70 lecturas por minuto) bastó
  // para dejar a WordPress sin workers para el resto del sitio. El 429 sale ANTES de
  // tocar WordPress; fail-open si Redis no responde, como todo rate-limit no estricto.
  const rl = await rateLimit(
    await cartReadLimitKey(request),
    CART_READ_LIMIT,
    CART_READ_WINDOW_SECONDS,
  );
  if (!rl.success) {
    logger.warn(
      { event: "cart.get.rate_limited" },
      "Rate-limit en la lectura del carrito",
    );
    return NextResponse.json(
      { error: "Demasiadas peticiones. Inténtalo más tarde." },
      { status: 429, headers: { "Retry-After": String(rl.retryAfter) } },
    );
  }

  try {
    const { cartToken: token, authToken } = await getCartRequestAuth();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart", {
      cartToken: token,
      authToken,
    });
    // Líneas sin existencias o por encima del máximo disponible: se quitan o
    // se reducen aquí, antes de que el cliente intente pagarlas.
    const reconciled = await applyCartStockAdjustments(data, { cartToken, authToken });
    await writeCartToken(reconciled.cartToken);
    logger.info(
      {
        event: "cart.get",
        authenticated: Boolean(authToken),
        stockAdjusted: reconciled.adjusted,
      },
      "Carrito consultado",
    );
    return NextResponse.json(await completeStoreCart(reconciled.cart));
  } catch (error) {
    logger.error(
      { event: "cart.get.error", err: error instanceof Error ? error.message : error },
      "Error al obtener el carrito",
    );
    return handleApiError(error);
  }
}

/** DELETE /api/store/cart — vacía el carrito por completo. */
export async function DELETE(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "cart", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;
  try {
    const { cartToken: token, authToken } = await getCartRequestAuth();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/items", {
      method: "DELETE",
      cartToken: token,
      authToken,
    });
    await writeCartToken(cartToken);
    logger.info({ event: "cart.clear" }, "Carrito vaciado");
    return NextResponse.json(await completeStoreCart(data));
  } catch (error) {
    logger.error(
      {
        event: "cart.clear.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error al vaciar el carrito",
    );
    return handleApiError(error);
  }
}
