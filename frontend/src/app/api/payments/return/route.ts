import { NextResponse } from "next/server";
import { handleApiError } from "@/lib/api/errors";
import { requireSession } from "@/lib/auth/session";
import { getOrder, isOrderPaid } from "@/lib/payments/orders";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

/**
 * GET /api/payments/return?ref=<orderId>
 * URL de retorno del usuario tras el checkout alojado. SOLO consulta el estado
 * del pedido para la UX; NUNCA confirma el pago (eso lo hace el webhook).
 *
 * Requiere sesión y propiedad del pedido (no filtra estados de otros usuarios).
 */
export async function GET(request: Request) {
  try {
    const session = await requireSession();

    const ref = new URL(request.url).searchParams.get("ref");
    if (!ref || !/^\d+$/.test(ref)) {
      logger.warn({ event: "payments.return.invalid_ref" }, "Referencia de retorno inválida");
      return NextResponse.json({ error: "Referencia inválida." }, { status: 400 });
    }

    const order = await getOrder(ref);
    if (order.customer_id !== Number(session.userId)) {
      logger.warn({ event: "payments.return.idor", userId: session.userId, ref }, "Intento de acceso a pedido de otro usuario");
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }

    logger.info({ event: "payments.return.success", userId: session.userId, ref, status: order.status }, "Retorno de pago consultado");
    return NextResponse.json({
      reference: String(order.id),
      status: order.status,
      paid: isOrderPaid(order),
      total: order.total,
      currency: order.currency,
    });
  } catch (error) {
    logger.error({ event: "payments.return.error", err: error instanceof Error ? error.message : error }, "Error en retorno de pago");
    return handleApiError(error);
  }
}
