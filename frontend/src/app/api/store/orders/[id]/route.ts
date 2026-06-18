import { NextResponse } from "next/server";
import { wcFetch } from "@/lib/woocommerce/client";
import { requireSession } from "@/lib/auth/session";
import { handleApiError } from "@/lib/api/errors";
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
      return NextResponse.json({ error: "Id de pedido inválido." }, { status: 400 });
    }

    const order = await wcFetch<WooOrder>(`/orders/${orderId}`);

    // El pedido debe pertenecer al usuario de la sesión.
    if (order.customer_id !== Number(session.userId)) {
      // No revelamos la existencia del recurso de otro usuario.
      return NextResponse.json({ error: "No encontrado." }, { status: 404 });
    }

    return NextResponse.json(order);
  } catch (error) {
    return handleApiError(error);
  }
}
