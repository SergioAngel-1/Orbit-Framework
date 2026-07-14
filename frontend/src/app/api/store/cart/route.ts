import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/** GET /api/store/cart — devuelve el carrito actual. */
export async function GET() {
  try {
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart", {
      cartToken: token,
    });
    await writeCartToken(cartToken);
    logger.info({ event: "cart.get" }, "Carrito consultado");
    return NextResponse.json(data);
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
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/items", {
      method: "DELETE",
      cartToken: token,
    });
    await writeCartToken(cartToken);
    logger.info({ event: "cart.clear" }, "Carrito vaciado");
    return NextResponse.json(data);
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
