import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { completeStoreCart } from "@/lib/woocommerce/complete-cart";
import { requestLocale } from "@/lib/i18n/request-locale";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { LOYALTY_CART_EXTENSION } from "@/lib/loyalty/cart";
import { requireLoyalty } from "@/lib/loyalty/guard";
import { cartPointsSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

// ============================================================================
//  POST /api/store/cart/points   → declara cuántos puntos usar (0 = ninguno).
//  DELETE /api/store/cart/points → quita el canje (equivale a enviar 0).
//
//  El navegador NUNCA envía importes: solo la cantidad. WordPress la recorta a
//  saldo, máximo por pedido y tope del carrito, calcula el descuento y lo
//  publica en `extensions["hwe-loyalty"]`. El BFF reenvía la cantidad al update
//  callback de la Store API con el `Cart-Token` y el JWT de la sesión, y
//  devuelve el carrito recalculado como el resto de rutas del carrito.
//
//  El canje exige sesión: sin cuenta no hay monedero que cargar.
// ============================================================================

const RATE_LIMIT = { name: "cart", limit: 60, windowSeconds: 60 } as const;

/**
 * Reenvía la cantidad pedida al update callback de la extensión y devuelve el
 * carrito. Se comparte entre POST y DELETE para que ambos caminos hagan
 * exactamente lo mismo (DELETE solo fija `points: 0`).
 */
async function updatePoints(request: Request, points: number): Promise<NextResponse> {
  const config = await getSiteConfig();
  const moduleOff = requireModule(config, "loyalty");
  if (moduleOff) return moduleOff;

  const featureOff = requireLoyalty(config, "points");
  if (featureOff) return featureOff;

  try {
    const { cartToken: token, authToken } = await getCartRequestAuth();
    if (!authToken) {
      logger.warn(
        { event: "cart.points.unauthenticated", points },
        "Canje intentado sin sesión",
      );
      return NextResponse.json({ error: "No autenticado." }, { status: 401 });
    }

    const { data, cartToken } = await storeFetch<StoreCart>("/cart/extensions", {
      method: "POST",
      body: {
        namespace: LOYALTY_CART_EXTENSION,
        data: { points },
      },
      cartToken: token,
      authToken,
    });
    if (cartToken) await writeCartToken(cartToken);

    logger.info(
      { event: points > 0 ? "cart.points.applied" : "cart.points.cleared", points },
      points > 0 ? "Puntos aplicados al carrito" : "Canje retirado del carrito",
    );
    return NextResponse.json(
      await completeStoreCart(data, requestLocale(request.headers)),
    );
  } catch (error) {
    logger.error(
      {
        event: "cart.points.error",
        points,
        err: error instanceof Error ? error.message : error,
      },
      "Error al actualizar el canje de puntos del carrito",
    );
    return handleApiError(error);
  }
}

/** POST /api/store/cart/points — aplica `{ points }` (entero ≥ 0). */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, { rateLimit: RATE_LIMIT });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    logger.warn({ event: "cart.points.invalid_json" }, "JSON inválido en el canje");
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = cartPointsSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "cart.points.validation_error" },
      "Cantidad de puntos inválida en el canje",
    );
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  return updatePoints(request, parsed.data.points);
}

/** DELETE /api/store/cart/points — quita el canje enviando 0. */
export async function DELETE(request: Request) {
  const blocked = await guardMutation(request, { rateLimit: RATE_LIMIT });
  if (blocked) return blocked;

  return updatePoints(request, 0);
}
