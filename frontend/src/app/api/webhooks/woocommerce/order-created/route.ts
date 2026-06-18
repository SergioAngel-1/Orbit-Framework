import { NextResponse } from "next/server";
import { verifyWooWebhook } from "@/lib/security/webhook";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

export async function POST(request: Request) {
  const rawBody = await request.text();
  const signature = request.headers.get("x-wc-webhook-signature");

  if (!verifyWooWebhook(rawBody, signature)) {
    logger.warn({ event: "webhook.order-created.invalid_signature" }, "Firma inválida en webhook order.created");
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

  logger.info({ event: "webhook.order-created", orderId, status }, "Pedido creado recibido vía webhook");

  // TODO: integraciones futuras — notificación email, ERP, facturación, etc.
  // Por ahora registramos el evento y respondemos OK.

  return NextResponse.json({ received: true, orderId, status, now: Date.now() });
}
