import { NextResponse } from "next/server";
import { wcFetch, WooCommerceError } from "@/lib/woocommerce/client";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { completeStoreCart } from "@/lib/woocommerce/complete-cart";
import { requestLocale } from "@/lib/i18n/request-locale";
import {
  REORDER_PRODUCT_FIELDS,
  planReorder,
  reorderProductIds,
  reorderReasonFromError,
  type ReorderProduct,
  type ReorderSummary,
} from "@/lib/account/reorder";
import { logger } from "@/lib/observability/logger";
import type { StoreCart, WooOrder } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * POST /api/store/orders/[id]/reorder
 * «Volver a pedir»: añade al carrito las líneas de un pedido anterior que hoy
 * se pueden comprar (ver `lib/account/reorder.ts`) y devuelve el carrito junto
 * a qué entró y qué no, para que la cuenta lo diga.
 *
 * Solo el dueño del pedido (anti-IDOR, como `GET /api/store/orders/[id]`). Las
 * líneas se añaden en serie: la primera puede crear el carrito y su
 * `Cart-Token` es el que usan las siguientes. Un rechazo de una línea no
 * aborta el resto.
 */
export async function POST(
  request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "reorder", limit: 10, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  try {
    const session = await requireSession();
    const { id } = await params;
    const orderId = Number(id);
    if (!Number.isInteger(orderId) || orderId <= 0) {
      return NextResponse.json({ error: "Id de pedido inválido." }, { status: 400 });
    }

    const order = await wcFetch<WooOrder>(`/orders/${orderId}`);
    if (order.customer_id !== Number(session.userId)) {
      logger.warn(
        { event: "orders.reorder.idor", userId: session.userId, orderId },
        "Intento de volver a pedir un pedido de otro usuario",
      );
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }

    const lines = order.line_items ?? [];
    const ids = reorderProductIds(lines);
    const products =
      ids.length > 0
        ? await wcFetch<ReorderProduct[]>("/products", {
            query: {
              include: ids.join(","),
              per_page: 100,
              _fields: REORDER_PRODUCT_FIELDS,
            },
          })
        : [];
    const plan = planReorder(lines, products);

    const auth = await getCartRequestAuth();
    let token = auth.cartToken;
    let cart: StoreCart | null = null;
    const summary: ReorderSummary = { added: [], skipped: [...plan.skipped] };

    for (const line of plan.add) {
      try {
        const result = await storeFetch<StoreCart>("/cart/add-item", {
          method: "POST",
          body: { id: line.productId, quantity: line.quantity },
          cartToken: token,
          authToken: auth.authToken,
        });
        token = result.cartToken;
        cart = result.data;
        summary.added.push({
          productId: line.productId,
          name: line.name,
          quantity: line.quantity,
          ...(line.requested ? { requested: line.requested } : {}),
        });
      } catch (error) {
        const code = error instanceof WooCommerceError ? error.code : undefined;
        logger.info(
          {
            event: "orders.reorder.line_rejected",
            orderId,
            productId: line.productId,
            code,
          },
          "Línea no añadida al volver a pedir",
        );
        summary.skipped.push({ name: line.name, reason: reorderReasonFromError(code) });
      }
    }

    // Sin ninguna línea añadida el carrito no cambió: se devuelve el actual
    // para que el cliente no pierda su copia.
    if (!cart) {
      const current = await storeFetch<StoreCart>("/cart", {
        cartToken: token,
        authToken: auth.authToken,
      });
      token = current.cartToken;
      cart = current.data;
    }
    await writeCartToken(token);

    logger.info(
      {
        event: "orders.reorder.success",
        orderId,
        added: summary.added.length,
        skipped: summary.skipped.length,
      },
      "Pedido vuelto a pedir",
    );
    return NextResponse.json({
      cart: await completeStoreCart(cart, requestLocale(request.headers)),
      ...summary,
    });
  } catch (error) {
    logger.error(
      {
        event: "orders.reorder.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error al volver a pedir",
    );
    return handleApiError(error);
  }
}
