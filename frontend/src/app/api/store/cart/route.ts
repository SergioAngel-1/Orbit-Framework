import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
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
    return NextResponse.json(data);
  } catch (error) {
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
    return NextResponse.json(data);
  } catch (error) {
    return handleApiError(error);
  }
}
