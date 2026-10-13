import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { completeStoreCart } from "@/lib/woocommerce/complete-cart";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { selectShippingRateSchema } from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/** PUT /api/store/shipping — selecciona una tarifa de envío. */
export async function PUT(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "shipping", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = selectShippingRateSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn({ event: "shipping.select.validation" }, "Datos de envío inválidos");
    return NextResponse.json(
      {
        error: "Datos de envío inválidos.",
        details: parsed.error.flatten().fieldErrors,
      },
      { status: 422 },
    );
  }

  try {
    const { cartToken: token, authToken } = await getCartRequestAuth();
    const { data, cartToken } = await storeFetch<StoreCart>(
      "/cart/select-shipping-rate",
      {
        method: "POST",
        body: { package_id: parsed.data.package_id, rate_id: parsed.data.rate_id },
        cartToken: token,
        authToken,
      },
    );
    await writeCartToken(cartToken);
    logger.info(
      { event: "shipping.select.success", rateId: parsed.data.rate_id },
      "Tarifa de envío seleccionada",
    );
    return NextResponse.json(await completeStoreCart(data));
  } catch (error) {
    logger.error(
      {
        event: "shipping.select.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error al seleccionar tarifa de envío",
    );
    return handleApiError(error);
  }
}
