import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { applyCouponSchema, removeCouponSchema } from "@/lib/validation/store";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/** POST /api/store/coupons — aplica un cupón al carrito. */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "coupon", limit: 20, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try { body = await request.json(); } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = applyCouponSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Código de cupón inválido.", details: parsed.error.flatten().fieldErrors },
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
    return NextResponse.json(data);
  } catch (error) {
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
  try { body = await request.json(); } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = removeCouponSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json({ error: "Código inválido." }, { status: 422 });
  }

  try {
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>(
      `/cart/coupons/${encodeURIComponent(parsed.data.code)}`,
      { method: "DELETE", cartToken: token },
    );
    await writeCartToken(cartToken);
    return NextResponse.json(data);
  } catch (error) {
    return handleApiError(error);
  }
}
