import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { completeStoreCart } from "@/lib/woocommerce/complete-cart";
import { requestLocale } from "@/lib/i18n/request-locale";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { applyCouponSchema, removeCouponSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import { getSiteConfig } from "@/lib/config";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

// ---------------------------------------------------------------------------
//  Ambos handlers usan los endpoints CART-LEVEL de la Store API
//  (`/cart/apply-coupon`, `/cart/remove-coupon`), que devuelven el CARRITO
//  completo. El CRUD de colección `/cart/coupons` NO sirve aquí: su POST
//  devuelve el CUPÓN creado (no el carrito) y su DELETE responde con cuerpo
//  vacío, así que el carrito quedaba roto en pantalla nada más aplicar uno.
// ---------------------------------------------------------------------------

/**
 * POST /api/store/coupons — aplica un cupón al carrito.
 *
 * NO exige sesión: si un cupón la pide lo decide cada cupón en WordPress
 * («Requiere sesión» del módulo `coupon-rules`, implícito en «Solo primera
 * compra»), que rechaza al invitado con un mensaje que lo invita a iniciar
 * sesión. Con sesión, el JWT viaja a la Store API para que esas reglas vean la
 * cuenta. Con `ecommerce.coupons_enabled` apagado no se aplica ninguno (403).
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "coupon", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  if (!(await getSiteConfig()).ecommerce.coupons_enabled) {
    return NextResponse.json(
      { error: "Los cupones no están activos." },
      { status: 403 },
    );
  }

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = applyCouponSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn({ event: "coupon.apply.validation" }, "Código de cupón inválido");
    return NextResponse.json(
      {
        error: "Código de cupón inválido.",
        details: parsed.error.flatten().fieldErrors,
      },
      { status: 422 },
    );
  }

  try {
    const { cartToken: token, authToken } = await getCartRequestAuth();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/apply-coupon", {
      method: "POST",
      body: { code: parsed.data.code },
      cartToken: token,
      authToken,
    });
    await writeCartToken(cartToken);
    logger.info(
      { event: "coupon.apply.success", code: parsed.data.code },
      "Cupón aplicado al carrito",
    );
    return NextResponse.json(
      await completeStoreCart(data, requestLocale(request.headers)),
    );
  } catch (error) {
    logger.error(
      {
        event: "coupon.apply.error",
        err: error instanceof Error ? error.message : error,
        code: parsed.data.code,
      },
      "Error al aplicar cupón",
    );
    return handleApiError(error);
  }
}

/**
 * DELETE /api/store/coupons — elimina un cupón del carrito. Sigue abierto con
 * los cupones desactivados: uno ya aplicado no puede quedar sin poder quitarse.
 */
export async function DELETE(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "coupon", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = removeCouponSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "coupon.remove.validation" },
      "Código de cupón inválido al eliminar",
    );
    return NextResponse.json({ error: "Código inválido." }, { status: 422 });
  }

  try {
    const { cartToken: token, authToken } = await getCartRequestAuth();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/remove-coupon", {
      method: "POST",
      body: { code: parsed.data.code },
      cartToken: token,
      authToken,
    });
    await writeCartToken(cartToken);
    logger.info(
      { event: "coupon.remove.success", code: parsed.data.code },
      "Cupón eliminado del carrito",
    );
    return NextResponse.json(
      await completeStoreCart(data, requestLocale(request.headers)),
    );
  } catch (error) {
    logger.error(
      {
        event: "coupon.remove.error",
        err: error instanceof Error ? error.message : error,
        code: parsed.data.code,
      },
      "Error al eliminar cupón",
    );
    return handleApiError(error);
  }
}
