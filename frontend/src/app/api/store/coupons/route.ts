import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { applyCouponSchema, removeCouponSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/** POST /api/store/coupons — aplica un cupón al carrito. */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "coupon", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
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
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/coupons", {
      method: "POST",
      body: { code: parsed.data.code },
      cartToken: token,
    });
    await writeCartToken(cartToken);
    logger.info(
      { event: "coupon.apply.success", code: parsed.data.code },
      "Cupón aplicado al carrito",
    );
    return NextResponse.json(data);
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

/** DELETE /api/store/coupons — elimina un cupón del carrito. */
export async function DELETE(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "coupon", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
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
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>(
      `/cart/coupons/${encodeURIComponent(parsed.data.code)}`,
      { method: "DELETE", cartToken: token },
    );
    await writeCartToken(cartToken);
    logger.info(
      { event: "coupon.remove.success", code: parsed.data.code },
      "Cupón eliminado del carrito",
    );
    return NextResponse.json(data);
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
