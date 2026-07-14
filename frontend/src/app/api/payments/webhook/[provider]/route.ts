import "@/lib/payments/providers"; // efecto secundario: registra las pasarelas
import { NextResponse } from "next/server";
import { handleApiError } from "@/lib/api/errors";
import { getProvider } from "@/lib/payments/registry";
import { rateLimit } from "@/lib/security/rate-limit";
import { markEventOnce } from "@/lib/security/replay";
import { getClientIp } from "@/lib/http/request-ip";
import { getOrCreateRequestId } from "@/lib/observability/request-id";
import { runWithRequestId } from "@/lib/observability/request-context";
import { logger } from "@/lib/observability/logger";
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

  // Rate-limit por IP (defensa en profundidad; el endpoint es server-to-server).
  const rl = await rateLimit(`pay_webhook:${getClientIp(request)}`, 60, 60);
  if (!rl.success) {
    return NextResponse.json({ error: "Demasiadas peticiones." }, { status: 429 });
  }

  // Cuerpo CRUDO: la firma se calcula sobre los bytes exactos recibidos.
  const rawBody = await request.text();
  const requestId = getOrCreateRequestId(request.headers);

  // Contexto de correlación: los `getOrder`/`markOrderPaid` (wc/v3) propagarán
  // este `X-Request-Id` a WordPress para seguir el pago de extremo a extremo.
  return runWithRequestId(requestId, async () => {
    logger.info(
      { event: "payments.webhook.received", provider: providerId, requestId },
      "Webhook recibido",
    );

    try {
      const provider = getProvider(providerId);
      const verification = await provider.verifyWebhook(rawBody, request.headers);

      if (!verification.valid || !verification.reference) {
        logger.warn(
          { event: "payments.webhook.invalid", provider: providerId },
          "Firma de webhook inválida",
        );
        return NextResponse.json(
          { error: "Firma de webhook inválida." },
          { status: 401 },
        );
      }

      logger.info(
        {
          event: "payments.webhook.verified",
          provider: providerId,
          reference: verification.reference,
          status: verification.status,
        },
        "Webhook verificado correctamente",
      );

      // Anti-replay: descartar reenvíos del MISMO evento firmado (ACK idempotente).
      const fresh = await markEventOnce(`payments:${providerId}`, rawBody);
      if (!fresh) {
        logger.info(
          {
            event: "payments.webhook.replay",
            provider: providerId,
            reference: verification.reference,
          },
          "Webhook duplicado descartado",
        );
        return NextResponse.json({ received: true, applied: "duplicate" });
      }

      const order = await getOrder(verification.reference);

      // Estados negativos: cancelamos si el pedido no estaba ya pagado.
      if (verification.status === "declined" || verification.status === "voided") {
        await markOrderCancelled(verification.reference);
        logger.info(
          { event: "payments.webhook.cancelled", reference: verification.reference },
          "Pedido cancelado por webhook",
        );
        return NextResponse.json({ received: true, applied: "cancelled" });
      }

      // Solo "approved" marca pagado. Cualquier otro estado se ignora (ACK).
      if (verification.status !== "approved") {
        logger.info(
          {
            event: "payments.webhook.ignored",
            reference: verification.reference,
            status: verification.status,
          },
          "Estado de webhook ignorado",
        );
        return NextResponse.json({ received: true, applied: "none" });
      }

      // Idempotencia: si ya estaba pagado, ACK sin reprocesar.
      if (isOrderPaid(order)) {
        logger.info(
          { event: "payments.webhook.already_paid", reference: verification.reference },
          "Pedido ya estaba pagado",
        );
        return NextResponse.json({ received: true, applied: "already_paid" });
      }

      // Conciliación: el importe y la moneda DEBEN coincidir con el pedido.
      if (
        !paymentMatchesOrder(order, verification.amountMinor, verification.currency)
      ) {
        logger.warn(
          { event: "payments.webhook.mismatch", reference: verification.reference },
          "El importe/moneda no coincide con el pedido",
        );
        return NextResponse.json(
          { error: "El importe/moneda no coincide con el pedido." },
          { status: 422 },
        );
      }

      await markOrderPaid(verification.reference, verification.providerTransactionId);
      logger.info(
        { event: "payments.webhook.paid", reference: verification.reference },
        "Pedido marcado como pagado",
      );
      return NextResponse.json({ received: true, applied: "paid" });
    } catch (error) {
      logger.error(
        {
          event: "payments.webhook.error",
          err: error instanceof Error ? error.message : error,
          provider: providerId,
        },
        "Error procesando webhook",
      );
      return handleApiError(error);
    }
  });
}
