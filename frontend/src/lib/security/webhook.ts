import "server-only";
import crypto from "node:crypto";

// ============================================================================
//  Verificación de firma de webhooks de WooCommerce.
//
//  WooCommerce firma el cuerpo crudo con HMAC-SHA256 usando el secreto del
//  webhook y lo envía en base64 en la cabecera `X-WC-Webhook-Signature`.
// ============================================================================

export function verifyWooWebhook(rawBody: string, signature: string | null): boolean {
  const secret = process.env.WC_WEBHOOK_SECRET;
  if (!secret || !signature) {
    return false;
  }

  const expected = crypto
    .createHmac("sha256", secret)
    .update(rawBody, "utf8")
    .digest("base64");

  try {
    const a = Buffer.from(signature);
    const b = Buffer.from(expected);
    if (a.length !== b.length) return false;
    return crypto.timingSafeEqual(a, b);
  } catch {
    return false;
  }
}
