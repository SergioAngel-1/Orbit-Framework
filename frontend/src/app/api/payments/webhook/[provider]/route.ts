import "@/lib/payments/providers"; // efecto secundario: registra las pasarelas
import { NextResponse } from "next/server";
import { handleApiError } from "@/lib/api/errors";
import { getProvider } from "@/lib/payments/registry";
import {
  getOrder,
  isOrderPaid,
  markOrderCancelled,
  markOrderPaid,
  paymentMatchesOrder,
} from "@/lib/payments/orders";

export const dynamic = "force-dynamic";

/**
 * POST /api/payments/webhook/[provider]
 * Handler único para cualquier pasarela registrada. Resuelve el proveedor por
 * el segmento de ruta, verifica la firma del evento y, SOLO si está aprobado y
 * el importe/moneda coinciden con el pedido, lo marca pagado (wc/v3).
 *
 * No lleva CSRF ni Origin (es server-to-server): la autenticidad la da la firma
 * del proveedor. Idempotente: reentregas no duplican el efecto.
 */
export async function POST(
  request: Request,
  { params }: { params: Promise<{ provider: string }> },
) {
  const { provider: providerId } = await params;

  // Cuerpo CRUDO: la firma se calcula sobre los bytes exactos recibidos.
  const rawBody = await request.text();

  try {
    const provider = getProvider(providerId);
    const verification = await provider.verifyWebhook(rawBody, request.headers);

    if (!verification.valid || !verification.reference) {
      return NextResponse.json(
        { error: "Firma de webhook inválida." },
        { status: 401 },
      );
    }

    const order = await getOrder(verification.reference);

    // Estados negativos: cancelamos si el pedido no estaba ya pagado.
    if (verification.status === "declined" || verification.status === "voided") {
      await markOrderCancelled(verification.reference);
      return NextResponse.json({ received: true, applied: "cancelled" });
    }

    // Solo "approved" marca pagado. Cualquier otro estado se ignora (ACK).
    if (verification.status !== "approved") {
      return NextResponse.json({ received: true, applied: "none" });
    }

    // Idempotencia: si ya estaba pagado, ACK sin reprocesar.
    if (isOrderPaid(order)) {
      return NextResponse.json({ received: true, applied: "already_paid" });
    }

    // Conciliación: el importe y la moneda DEBEN coincidir con el pedido.
    if (!paymentMatchesOrder(order, verification.amountMinor, verification.currency)) {
      return NextResponse.json(
        { error: "El importe/moneda no coincide con el pedido." },
        { status: 422 },
      );
    }

    await markOrderPaid(verification.reference, verification.providerTransactionId);
    return NextResponse.json({ received: true, applied: "paid" });
  } catch (error) {
    return handleApiError(error);
  }
}
