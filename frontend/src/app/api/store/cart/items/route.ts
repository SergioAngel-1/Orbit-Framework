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
    return NextResponse.json(data);
  } catch (error) {
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
    return NextResponse.json(data);
  } catch (error) {
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
    return NextResponse.json(data);
  } catch (error) {
    return handleApiError(error);
  }
}
