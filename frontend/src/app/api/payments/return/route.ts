import { NextResponse } from "next/server";
import { handleApiError } from "@/lib/api/errors";
import { getSession } from "@/lib/auth/session";
import { readGuestOrderCredential } from "@/lib/checkout/guest-order-cookie";
import { canAccessOrder } from "@/lib/checkout/order-access";
import { getOrder, isOrderPaid } from "@/lib/payments/orders";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

/**
 * GET /api/payments/return?ref=<orderId>
 * URL de retorno del usuario tras el checkout alojado. SOLO consulta el estado
 * del pedido para la UX; NUNCA confirma el pago (eso lo hace el webhook).
 *
 * Requiere propiedad del pedido —por sesión o, si es de invitado, por la
 * credencial `order_key` de su cookie— (no filtra estados de otros usuarios).
 */
export async function GET(request: Request) {
  try {
    const [session, guest] = await Promise.all([
      getSession(),
      readGuestOrderCredential(),
    ]);
    if (!session && !guest) throw new Error("UNAUTHENTICATED");

    const ref = new URL(request.url).searchParams.get("ref");
    if (!ref || !/^\d+$/.test(ref)) {
      logger.warn(
        { event: "payments.return.invalid_ref" },
        "Referencia de retorno inválida",
      );
      return NextResponse.json({ error: "Referencia inválida." }, { status: 400 });
    }

    const order = await getOrder(ref);
    if (!canAccessOrder(order, { userId: session?.userId, guest })) {
      logger.warn(
        { event: "payments.return.idor", userId: session?.userId ?? null, ref },
        "Intento de acceso a pedido de otro usuario",
      );
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }

    logger.info(
      {
        event: "payments.return.success",
        userId: session?.userId ?? null,
        ref,
        status: order.status,
      },
      "Retorno de pago consultado",
    );
    return NextResponse.json({
      reference: String(order.id),
      status: order.status,
      paid: isOrderPaid(order),
      total: order.total,
      currency: order.currency,
    });
  } catch (error) {
    logger.error(
      {
        event: "payments.return.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error en retorno de pago",
    );
    return handleApiError(error);
  }
}
