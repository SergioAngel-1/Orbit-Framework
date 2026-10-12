import "server-only";
import crypto from "node:crypto";

// ============================================================================
//  Verificación de firmas HMAC-SHA256 de webhooks, en tiempo constante
//  (crypto.timingSafeEqual). Un único verificador para todos los consumidores;
//  soporta los dos esquemas usados en este proyecto: base64 sin prefijo
//  (WooCommerce) y hex con prefijo `sha256=` (HWE Control Center, y esquemas
//  estilo GitHub/Stripe).
//
//  La comparación es sobre la firma CODIFICADA tal como llega, no sobre los bytes
//  que resultan de decodificarla: `Buffer.from(firma, "hex" | "base64")` es
//  permisivo (descarta en silencio la basura final, el relleno que falta y los
//  saltos de línea), así que varias cadenas distintas pasarían por la misma firma.
// ============================================================================

export function verifyHmacSignature(
  rawBody: string,
  signature: string,
  secret: string,
  opts: { encoding: "base64" | "hex"; prefix?: string },
): boolean {
  // Con un secreto vacío HMAC sigue funcionando, y cualquiera sabría firmar:
  // sin secreto no hay verificación posible, así que falla cerrado.
  if (!secret || !signature) return false;

  let received = signature;
  if (opts.prefix) {
    if (!signature.startsWith(opts.prefix)) return false;
    received = signature.slice(opts.prefix.length);
  }

  const expected = crypto
    .createHmac("sha256", secret)
    .update(rawBody, "utf8")
    .digest(opts.encoding);

  const a = Buffer.from(received);
  const b = Buffer.from(expected);
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}

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

  return verifyHmacSignature(rawBody, signature, secret, { encoding: "base64" });
}
