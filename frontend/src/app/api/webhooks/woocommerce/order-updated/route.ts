import { NextResponse } from "next/server";
import { verifyWooWebhook } from "@/lib/security/webhook";
import { markEventOnce } from "@/lib/security/replay";
import { rateLimit } from "@/lib/security/rate-limit";
import { getClientIp } from "@/lib/http/request-ip";
import { recordAndDiffStatus, dispatchOrderEffects } from "@/lib/woocommerce/order-events";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

/**
 * POST /api/webhooks/woocommerce/order-updated
 * Webhook firmado de WooCommerce para `order.updated`.
 *
 * El payload solo trae el estado ACTUAL; el estado anterior se recupera de
 * Redis (lo persiste `recordAndDiffStatus`) para poder razonar sobre la
 * transición. Los efectos (email, inventario, ERP…) se delegan al dispatcher
 * único en `lib/woocommerce/order-events.ts`.
 */
export async function POST(request: Request) {
  const rl = await rateLimit(`wc_webhook:${getClientIp(request)}`, 120, 60);
  if (!rl.success) {
    return NextResponse.json({ error: "Demasiadas peticiones." }, { status: 429 });
  }

  const rawBody = await request.text();
  const signature = request.headers.get("x-wc-webhook-signature");

  if (!verifyWooWebhook(rawBody, signature)) {
    logger.warn({ event: "webhook.order-updated.invalid_signature" }, "Firma inválida en webhook order.updated");
    return NextResponse.json({ error: "Firma de webhook inválida." }, { status: 401 });
  }

  // Anti-replay: descartar reenvíos idénticos del mismo evento firmado.
  if (!(await markEventOnce("wc:order-updated", rawBody))) {
    logger.info({ event: "webhook.order-updated.replay" }, "Webhook duplicado descartado");
    return NextResponse.json({ received: true, duplicate: true });
  }

  let payload: Record<string, unknown> = {};
  try {
    payload = JSON.parse(rawBody);
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const orderId = payload.id as number | string | undefined;
  const status = String(payload.status ?? "");

  if (orderId == null || status === "") {
    return NextResponse.json({ error: "Payload de pedido incompleto." }, { status: 400 });
  }

  // Estado anterior real (no el actual): lo recuperamos del almacén y guardamos
  // el nuevo. Si Redis no está, `previousStatus` será null (no-diferencial).
  const previousStatus = await recordAndDiffStatus(orderId, status);

  logger.info(
    { event: "webhook.order-updated", orderId, status, previousStatus },
    "Pedido actualizado recibido vía webhook",
  );

  await dispatchOrderEffects({ event: "order.updated", orderId, status, previousStatus, payload });

  return NextResponse.json({ received: true, orderId, status, previousStatus, now: Date.now() });
}
