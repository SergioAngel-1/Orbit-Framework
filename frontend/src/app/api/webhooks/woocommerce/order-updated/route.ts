import { NextResponse } from "next/server";
import { verifyWooWebhook } from "@/lib/security/webhook";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  const rawBody = await request.text();
  const signature = request.headers.get("x-wc-webhook-signature");

  if (!verifyWooWebhook(rawBody, signature)) {
    logger.warn({ event: "webhook.order-updated.invalid_signature" }, "Firma inválida en webhook order.updated");
    return NextResponse.json({ error: "Firma de webhook inválida." }, { status: 401 });
  }

  let payload: Record<string, unknown> = {};
  try {
    payload = JSON.parse(rawBody);
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const orderId = payload.id;
  const status = payload.status;
  const previousStatus = payload.status; // WooCommerce envía el estado actual

  logger.info({ event: "webhook.order-updated", orderId, status, previousStatus }, "Pedido actualizado recibido vía webhook");

  // TODO: integraciones futuras — sincronización de inventario, tracking, etc.
  // Por ahora registramos el evento y respondemos OK.

  return NextResponse.json({ received: true, orderId, status, now: Date.now() });
}
