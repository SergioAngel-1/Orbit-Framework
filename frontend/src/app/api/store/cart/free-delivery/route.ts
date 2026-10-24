import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { WooCommerceError } from "@/lib/woocommerce/client";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { completeStoreCart } from "@/lib/woocommerce/complete-cart";
import { requestLocale } from "@/lib/i18n/request-locale";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { MEMBERSHIPS_CART_EXTENSION } from "@/lib/memberships/cart";
import {
  membershipCheckoutError,
  membershipCheckoutMessage,
} from "@/lib/memberships/checkout";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

// ============================================================================
//  POST /api/store/cart/free-delivery   → pide usar un envío gratis.
//  DELETE /api/store/cart/free-delivery → deja de usarlo.
//
//  El método HTTP es la declaración completa: el cuerpo se ignora. Cualquier
//  importe, cupo o clave extra que llegue en la petición NO viaja a
//  WordPress. El BFF reenvía solo `{free_delivery: true|false}` al update
//  callback de la extensión `hwe-memberships` con el `Cart-Token` y el JWT de
//  la sesión; WordPress decide si el beneficio se aplica y devuelve el carrito
//  recalculado, que es lo que sale al navegador.
//
//  El envío gratis es un beneficio de la membresía: exige sesión.
// ============================================================================

const RATE_LIMIT = { name: "cart", limit: 60, windowSeconds: 60 } as const;

/**
 * Reenvía la declaración al update callback de la extensión y devuelve el
 * carrito recalculado. Se comparte entre POST y DELETE para que ambos caminos
 * hagan exactamente lo mismo (cambia solo el booleano).
 */
async function updateFreeDelivery(
  request: Request,
  requested: boolean,
): Promise<NextResponse> {
  try {
    const { cartToken: token, authToken } = await getCartRequestAuth();
    if (!authToken) {
      logger.warn(
        { event: "cart.free_delivery.unauthenticated", requested },
        "Envío gratis de membresía intentado sin sesión",
      );
      return NextResponse.json({ error: "No autenticado." }, { status: 401 });
    }

    const { data, cartToken } = await storeFetch<StoreCart>("/cart/extensions", {
      method: "POST",
      body: {
        namespace: MEMBERSHIPS_CART_EXTENSION,
        data: { free_delivery: requested },
      },
      cartToken: token,
      authToken,
    });
    if (cartToken) await writeCartToken(cartToken);

    logger.info(
      {
        event: requested ? "cart.free_delivery.applied" : "cart.free_delivery.cleared",
      },
      requested
        ? "Envío gratis declarado en el carrito"
        : "Uso de envío gratis retirado del carrito",
    );
    return NextResponse.json(
      await completeStoreCart(data, requestLocale(request.headers)),
    );
  } catch (error) {
    if (error instanceof WooCommerceError) {
      const membership = membershipCheckoutError(error.code, error.data);
      if (membership) {
        logger.warn(
          {
            event: "cart.free_delivery.membership_error",
            reason: membership.reason,
            requested,
          },
          "La Store API rechazó la declaración de envío gratis de la membresía",
        );
        return NextResponse.json(
          {
            error: membershipCheckoutMessage(membership.reason),
            reason: membership.reason,
            ...membership.details,
          },
          { status: 409 },
        );
      }
    }

    logger.error(
      {
        event: "cart.free_delivery.error",
        requested,
        err: error instanceof Error ? error.message : error,
      },
      "Error al actualizar el envío gratis de membresía del carrito",
    );
    return handleApiError(error);
  }
}

/**
 * Guardas comunes: el módulo apagado no existe para el frontend (404) y toda
 * escritura pasa por Origin + CSRF + rate-limit antes de tocar WordPress.
 */
async function handle(request: Request, requested: boolean): Promise<NextResponse> {
  const moduleOff = requireModule(await getSiteConfig(), "memberships");
  if (moduleOff) return moduleOff;

  const blocked = await guardMutation(request, { rateLimit: RATE_LIMIT });
  if (blocked) return blocked;

  return updateFreeDelivery(request, requested);
}

/** POST /api/store/cart/free-delivery — declara el uso de un envío gratis. */
export async function POST(request: Request) {
  return handle(request, true);
}

/** DELETE /api/store/cart/free-delivery — retira la declaración. */
export async function DELETE(request: Request) {
  return handle(request, false);
}
