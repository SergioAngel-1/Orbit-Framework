import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { selectShippingRateSchema } from "@/lib/validation/store";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/** PUT /api/store/shipping — selecciona una tarifa de envío. */
export async function PUT(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "shipping", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try { body = await request.json(); } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = selectShippingRateSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos de envío inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  try {
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/select-shipping-rate", {
      method: "POST",
      body: { package_id: parsed.data.package_id, rate_id: parsed.data.rate_id },
      cartToken: token,
    });
    await writeCartToken(cartToken);
    return NextResponse.json(data);
  } catch (error) {
    return handleApiError(error);
  }
}
