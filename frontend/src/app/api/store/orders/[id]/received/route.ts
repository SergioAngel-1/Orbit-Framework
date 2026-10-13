import { NextResponse } from "next/server";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { canConfirmReceipt, reviewableProductIds } from "@/lib/account/order-review";
import { invalidateCachedEligibility } from "@/lib/account/review-eligibility";
import { logger } from "@/lib/observability/logger";
import type { WooOrder } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/** Meta del pedido con cuándo confirmó el cliente que lo recibió. */
const RECEIVED_META = "_hwe_received_by_customer";

/**
 * POST /api/store/orders/[id]/received
 * «Recibí mi pedido»: el cliente pasa su pedido de «Procesando» a
 * «Completado», lo que habilita calificarlo (`canRateOrder`).
 *
 * Solo el dueño con sesión (anti-IDOR, como el resto de `orders/[id]`) y solo
 * desde «Procesando»: un pedido sin pagar no se completa desde aquí. Si ya está
 * completado responde 200 (doble clic, otra pestaña). El cambio de estado lo
 * hace WooCommerce como cualquier otro, así que sale su correo de «Pedido
 * completado» y el webhook `order.updated`. Queda constancia en el pedido: la
 * meta `_hwe_received_by_customer` (fecha) y una nota privada para el equipo.
 * Lo llaman la cuenta («Recibí mi pedido») y la ficha de cualquiera de sus
 * productos («Recibí mi producto»): en los dos casos se completa el pedido
 * ENTERO.
 */
export async function POST(
  request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "order_received", limit: 10, windowSeconds: 3600 },
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
        { event: "orders.received.idor", userId: session.userId, orderId },
        "Intento de marcar como recibido un pedido de otro usuario",
      );
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }
    if (order.status === "completed") {
      return NextResponse.json({ status: "completed" });
    }
    if (!canConfirmReceipt(order.status)) {
      return NextResponse.json(
        { error: "Este pedido no se puede marcar como recibido." },
        { status: 422 },
      );
    }

    const receivedAt = new Date().toISOString();
    await wcFetch<WooOrder>(`/orders/${orderId}`, {
      method: "PUT",
      body: {
        status: "completed",
        meta_data: [{ key: RECEIVED_META, value: receivedAt }],
      },
    });
    // La ficha de cada producto del pedido tenía cacheado «not_received»: sin
    // esto, «Recibí mi producto» seguiría ahí y el formulario no aparecería.
    await Promise.all(
      reviewableProductIds(order.line_items ?? []).map((pid) =>
        invalidateCachedEligibility(session.userId, pid),
      ),
    );
    // La nota es para el equipo (wp-admin); si falla, el pedido ya cambió.
    await wcFetch(`/orders/${orderId}/notes`, {
      method: "POST",
      body: {
        note: "El cliente confirmó desde el sitio que recibió el pedido («Recibí mi pedido»).",
        customer_note: false,
      },
    }).catch((error) =>
      logger.warn(
        {
          event: "orders.received.note_error",
          orderId,
          err: error instanceof Error ? error.message : error,
        },
        "No se pudo añadir la nota de pedido recibido",
      ),
    );

    logger.info(
      { event: "orders.received.success", userId: session.userId, orderId },
      "Pedido marcado como recibido por el cliente",
    );
    return NextResponse.json({ status: "completed" });
  } catch (error) {
    logger.error(
      {
        event: "orders.received.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error al marcar el pedido como recibido",
    );
    return handleApiError(error);
  }
}
