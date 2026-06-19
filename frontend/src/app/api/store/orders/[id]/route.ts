import { NextResponse } from "next/server";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { handleApiError } from "@/lib/api/errors";
import { logger } from "@/lib/observability/logger";
import type { WooOrder } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * GET /api/store/orders/[id]
 * Devuelve un pedido SOLO si pertenece al usuario autenticado.
 * Las credenciales ck/cs son de administrador, por eso la autorización por
 * propietario es obligatoria aquí (previene IDOR).
 */
export async function GET(
  _request: Request,
  { params }: { params: Promise<{ id: string }> },
) {
  try {
    const session = await requireSession();

    const { id } = await params;
    const orderId = Number(id);
    if (!Number.isInteger(orderId) || orderId <= 0) {
      logger.warn({ event: "orders.get.invalid_id" }, "ID de pedido inválido");
      return NextResponse.json({ error: "Id de pedido inválido." }, { status: 400 });
    }

    const order = await wcFetch<WooOrder>(`/orders/${orderId}`);

    // El pedido debe pertenecer al usuario de la sesión.
    if (order.customer_id !== Number(session.userId)) {
      logger.warn({ event: "orders.get.idor", userId: session.userId, orderId }, "Intento de acceso a pedido de otro usuario");
      // No revelamos la existencia del recurso de otro usuario.
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }

    logger.info({ event: "orders.get.success", userId: session.userId, orderId }, "Pedido consultado");
    return NextResponse.json(order);
  } catch (error) {
    logger.error({ event: "orders.get.error", err: error instanceof Error ? error.message : error }, "Error al obtener pedido");
    return handleApiError(error);
  }
}
