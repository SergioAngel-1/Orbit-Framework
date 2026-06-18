import { NextResponse } from "next/server";
import { handleApiError } from "@/lib/api/errors";
import { requireSession } from "@/lib/auth/session";
import { getOrder, isOrderPaid } from "@/lib/payments/orders";

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
      return NextResponse.json({ error: "Referencia inválida." }, { status: 400 });
    }

    const order = await getOrder(ref);
    if (order.customer_id !== Number(session.userId)) {
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }

    return NextResponse.json({
      reference: String(order.id),
      status: order.status,
      paid: isOrderPaid(order),
      total: order.total,
      currency: order.currency,
    });
  } catch (error) {
    return handleApiError(error);
  }
}
