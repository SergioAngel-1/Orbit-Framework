import "@/lib/payments/providers"; // efecto secundario: registra las pasarelas
import { NextResponse, after } from "next/server";
import {
  BodyTooLarge,
  MAX_WEBHOOK_BODY_BYTES,
  bodyTooLarge,
  readTextBody,
} from "@/lib/api/body";
import { handleApiError } from "@/lib/api/errors";
import { acceptsWebhooksFrom, getProvider } from "@/lib/payments/registry";
import { rateLimit } from "@/lib/security/rate-limit";
import { markEventOnce, releaseEvent } from "@/lib/security/replay";
import { getClientIp } from "@/lib/http/request-ip";
import { getOrCreateRequestId } from "@/lib/observability/request-id";
import { runWithRequestId } from "@/lib/observability/request-context";
import { logger, requestLogger } from "@/lib/observability/logger";
import { sendPurchaseToMeta } from "@/lib/analytics/meta-capi";
import {
  getOrder,
  isOrderPaid,
  markOrderCancelled,
  markOrderPaid,
  paymentMatchesOrder,
} from "@/lib/payments/orders";

export const dynamic = "force-dynamic";

/**
 * Manda la compra a la Conversions API de Meta DESPUÉS de responder a la
 * pasarela: la medición no puede retrasar ni hacer fallar el webhook. No lanza
 * (`sendPurchaseToMeta` ya lo garantiza) y sin consentimiento, sin credenciales
 * o con un pedido que no cuenta como compra, no hace nada.
 */
function measurePurchase(order: Parameters<typeof sendPurchaseToMeta>[0]): void {
  after(async () => {
    try {
      await sendPurchaseToMeta(order);
    } catch (error) {
      logger.warn(
        {
          event: "payments.webhook.measure_failed",
          err: error instanceof Error ? error.message : error,
        },
        "No se pudo medir la compra",
      );
    }
  });
}

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
  // params + rate-limit por IP (defensa en profundidad), en paralelo.
  const [{ provider: providerId }, rl] = await Promise.all([
    params,
    rateLimit(`pay_webhook:${getClientIp(request)}`, 60, 60),
  ]);
  if (!rl.success) {
    return NextResponse.json({ error: "Demasiadas peticiones." }, { status: 429 });
  }

  // La pasarela de pruebas firma con un secreto público: fuera de su uso (activa
  // en desarrollo/CI) no existe para el exterior. Una pasarela no registrada,
  // tampoco.
  if (!(await acceptsWebhooksFrom(providerId))) {
    return NextResponse.json({ error: "No encontrado." }, { status: 404 });
  }

  // Cuerpo CRUDO con tope duro: la firma se calcula sobre los bytes exactos
  // recibidos, pero nunca se materializa más de `MAX_WEBHOOK_BODY_BYTES`.
  let rawBody: string;
  try {
    rawBody = await readTextBody(request, MAX_WEBHOOK_BODY_BYTES);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    throw error;
  }
  const requestId = getOrCreateRequestId(request.headers);

  // Contexto de correlación: los `getOrder`/`markOrderPaid` (wc/v3) propagarán
  // este `X-Request-Id` a WordPress para seguir el pago de extremo a extremo.
  return runWithRequestId(requestId, async () => {
    // Logger con el `requestId` ya en cada línea (lo toma del contexto de arriba).
    const log = requestLogger();
    log.info(
      { event: "payments.webhook.received", provider: providerId },
      "Webhook recibido",
    );

    // Ámbito del anti-replay. La marca se pone antes de tocar el pedido (hace
    // también de cerrojo entre entregas simultáneas), así que se suelta en todo
    // camino que NO deja el evento resuelto: si no, el reintento de la pasarela
    // se descarta como duplicado y el cobro no se aplica jamás.
    const replayScope = `payments:${providerId}`;
    let markedFresh = false;

    try {
      const provider = getProvider(providerId);
      const verification = await provider.verifyWebhook(rawBody, request.headers);

      if (!verification.valid || !verification.reference) {
        log.warn(
          { event: "payments.webhook.invalid", provider: providerId },
          "Firma de webhook inválida",
        );
        return NextResponse.json(
          { error: "Firma de webhook inválida." },
          { status: 401 },
        );
      }

      log.info(
        {
          event: "payments.webhook.verified",
          provider: providerId,
          reference: verification.reference,
          status: verification.status,
        },
        "Webhook verificado correctamente",
      );

      // Anti-replay: descartar reenvíos del MISMO evento firmado (ACK idempotente).
      const fresh = await markEventOnce(replayScope, rawBody);
      markedFresh = fresh;
      if (!fresh) {
        log.info(
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
        log.info(
          { event: "payments.webhook.cancelled", reference: verification.reference },
          "Pedido cancelado por webhook",
        );
        return NextResponse.json({ received: true, applied: "cancelled" });
      }

      // Solo "approved" marca pagado. Cualquier otro estado se ignora (ACK).
      if (verification.status !== "approved") {
        log.info(
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
        log.info(
          { event: "payments.webhook.already_paid", reference: verification.reference },
          "Pedido ya estaba pagado",
        );
        // Un reintento de la pasarela: si el envío anterior no salió, la marca
        // del pedido (`_hwe_meta_capi_purchase_sent`) evita duplicarlo.
        measurePurchase(order);
        return NextResponse.json({ received: true, applied: "already_paid" });
      }

      // Conciliación: el importe y la moneda DEBEN coincidir con el pedido.
      if (
        !paymentMatchesOrder(
          order,
          verification.amountMinor,
          verification.currency,
          verification.minorUnit,
        )
      ) {
        // 422 (no-2xx): la pasarela reintentará; se suelta la marca para que ese
        // reintento se evalúe de verdad en vez de recibir «duplicado».
        await releaseEvent(replayScope, rawBody);
        log.warn(
          { event: "payments.webhook.mismatch", reference: verification.reference },
          "El importe/moneda no coincide con el pedido",
        );
        return NextResponse.json(
          { error: "El importe/moneda no coincide con el pedido." },
          { status: 422 },
        );
      }

      const paidOrder = await markOrderPaid(
        verification.reference,
        verification.providerTransactionId,
      );
      log.info(
        { event: "payments.webhook.paid", reference: verification.reference },
        "Pedido marcado como pagado",
      );
      measurePurchase(paidOrder);
      return NextResponse.json({ received: true, applied: "paid" });
    } catch (error) {
      // El evento quedó a medias (WooCommerce caído, timeout de wc/v3…): se suelta
      // la marca para que el reintento de la pasarela pueda aplicarlo.
      if (markedFresh) {
        await releaseEvent(replayScope, rawBody);
      }
      log.error(
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
