import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { readCartToken, writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import {
  addItemSchema,
  updateItemSchema,
  removeItemSchema,
} from "@/lib/validation/store";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

async function readBody(request: Request): Promise<unknown> {
  try {
    return await request.json();
  } catch {
    return null;
  }
}

/** POST /api/store/cart/items — añade un producto al carrito. */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "cart", limit: 60, windowSeconds: 60 },
  });
  if (blocked) return blocked;
  const parsed = addItemSchema.safeParse(await readBody(request));
  if (!parsed.success) {
    logger.warn({ event: "cart.add_item.validation" }, "Datos inválidos al añadir producto al carrito");
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }
  try {
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/add-item", {
      method: "POST",
      body: parsed.data,
      cartToken: token,
    });
    await writeCartToken(cartToken);
    logger.info({ event: "cart.add_item.success", productId: parsed.data.id }, "Producto añadido al carrito");
    return NextResponse.json(data);
  } catch (error) {
    logger.error({ event: "cart.add_item.error", err: error instanceof Error ? error.message : error }, "Error al añadir producto al carrito");
    return handleApiError(error);
  }
}

/** PATCH /api/store/cart/items — actualiza la cantidad de una línea. */
export async function PATCH(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "cart", limit: 60, windowSeconds: 60 },
  });
  if (blocked) return blocked;
  const parsed = updateItemSchema.safeParse(await readBody(request));
  if (!parsed.success) {
    logger.warn({ event: "cart.update_item.validation" }, "Datos inválidos al actualizar ítem del carrito");
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }
  try {
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/update-item", {
      method: "POST",
      body: parsed.data,
      cartToken: token,
    });
    await writeCartToken(cartToken);
    logger.info({ event: "cart.update_item.success", key: parsed.data.key }, "Ítem del carrito actualizado");
    return NextResponse.json(data);
  } catch (error) {
    logger.error({ event: "cart.update_item.error", err: error instanceof Error ? error.message : error }, "Error al actualizar ítem del carrito");
    return handleApiError(error);
  }
}

/** DELETE /api/store/cart/items — quita una línea del carrito (por `key`). */
export async function DELETE(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "cart", limit: 60, windowSeconds: 60 },
  });
  if (blocked) return blocked;
  const parsed = removeItemSchema.safeParse(await readBody(request));
  if (!parsed.success) {
    logger.warn({ event: "cart.remove_item.validation" }, "Datos inválidos al eliminar ítem del carrito");
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }
  try {
    const token = await readCartToken();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/remove-item", {
      method: "POST",
      body: parsed.data,
      cartToken: token,
    });
    await writeCartToken(cartToken);
    logger.info({ event: "cart.remove_item.success", key: parsed.data.key }, "Ítem eliminado del carrito");
    return NextResponse.json(data);
  } catch (error) {
    logger.error({ event: "cart.remove_item.error", err: error instanceof Error ? error.message : error }, "Error al eliminar ítem del carrito");
    return handleApiError(error);
  }
}
