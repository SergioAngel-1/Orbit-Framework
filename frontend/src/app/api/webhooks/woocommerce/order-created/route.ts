import { NextResponse } from "next/server";
import {
  BodyTooLarge,
  MAX_WEBHOOK_BODY_BYTES,
  bodyTooLarge,
  readTextBody,
} from "@/lib/api/body";
import { verifyWooWebhook } from "@/lib/security/webhook";
import { markEventOnce } from "@/lib/security/replay";
import { rateLimit } from "@/lib/security/rate-limit";
import { parseWebhookOrderId } from "@/lib/woocommerce/order-id";
import { getClientIp } from "@/lib/http/request-ip";
import {
  recordAndDiffStatus,
  dispatchOrderEffects,
} from "@/lib/woocommerce/order-events";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

/**
 * POST /api/webhooks/woocommerce/order-created
 * Webhook firmado de WooCommerce para `order.created`.
 *
 * Registra el estado inicial del pedido (semilla para razonar transiciones
 * posteriores en `order.updated`) y delega los efectos al dispatcher único.
 */
export async function POST(request: Request) {
  const rl = await rateLimit(`wc_webhook:${getClientIp(request)}`, 120, 60);
  if (!rl.success) {
    return NextResponse.json({ error: "Demasiadas peticiones." }, { status: 429 });
  }

  // Cuerpo CRUDO con tope duro: la firma se verifica sobre estos bytes, pero
  // nunca se materializa más de `MAX_WEBHOOK_BODY_BYTES` (413 si se pasa).
  let rawBody: string;
  try {
    rawBody = await readTextBody(request, MAX_WEBHOOK_BODY_BYTES);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    throw error;
  }
  const signature = request.headers.get("x-wc-webhook-signature");

  if (!verifyWooWebhook(rawBody, signature)) {
    logger.warn(
      { event: "webhook.order-created.invalid_signature" },
      "Firma inválida en webhook order.created",
    );
    return NextResponse.json({ error: "Firma de webhook inválida." }, { status: 401 });
  }

  // Anti-replay: descartar reenvíos del mismo evento firmado.
  if (!(await markEventOnce("wc:order-created", rawBody))) {
    logger.info(
      { event: "webhook.order-created.replay" },
      "Webhook duplicado descartado",
    );
    return NextResponse.json({ received: true, duplicate: true });
  }

  let payload: Record<string, unknown> = {};
  try {
    payload = JSON.parse(rawBody);
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  // El id se interpola en una clave de Redis y llega a los efectos: solo se admite
  // un id de pedido válido (ver `order-id.ts`), no el cast de lo que mande el emisor.
  const orderId = parseWebhookOrderId(payload.id);
  const status = String(payload.status ?? "");

  if (orderId === null || status === "") {
    logger.warn(
      { event: "webhook.order-created.invalid_payload" },
      "Payload de pedido sin id válido o sin estado",
    );
    return NextResponse.json(
      { error: "Payload de pedido incompleto o con id inválido." },
      { status: 400 },
    );
  }

  // Semilla del estado inicial (en creación no hay transición previa).
  await recordAndDiffStatus(orderId, status);

  logger.info(
    { event: "webhook.order-created", orderId, status },
    "Pedido creado recibido vía webhook",
  );

  await dispatchOrderEffects({
    event: "order.created",
    orderId,
    status,
    previousStatus: null,
    payload,
  });

  return NextResponse.json({ received: true, orderId, status, now: Date.now() });
}
