import "server-only";
import crypto from "node:crypto";
import { getRedis } from "@/lib/redis/client";

// ============================================================================
//  Anti-replay para webhooks (pagos y WooCommerce).
//
//  La firma HMAC garantiza autenticidad e integridad, pero NO frescura: un
//  atacante que capture un evento firmado válido podría reenviarlo. Registramos
//  un hash del cuerpo en Redis (SET NX con TTL) para procesar cada evento una
//  sola vez dentro de la ventana.
//
//  Fail-open (coherente con el resto): sin Redis no podemos deduplicar, así que
//  dejamos pasar (la idempotencia de negocio —estado del pedido— sigue actuando
//  como segunda línea de defensa).
// ============================================================================

const PREFIX = "replay:";
const DEFAULT_TTL = 60 * 60 * 24; // 24 h

/**
 * Marca el evento como procesado. Devuelve `true` si es la PRIMERA vez (procede)
 * y `false` si ya se había visto (replay → descartar).
 *
 * @param scope   Espacio de nombres lógico, p. ej. "wc" o `payments:${provider}`.
 * @param rawBody Cuerpo crudo exacto (sobre el que se calculó la firma).
 */
export async function markEventOnce(
  scope: string,
  rawBody: string,
  ttlSeconds: number = DEFAULT_TTL,
): Promise<boolean> {
  const redis = getRedis();
  if (!redis) return true; // sin Redis: no podemos deduplicar (fail-open)

  const hash = crypto.createHash("sha256").update(rawBody).digest("hex");
  const key = `${PREFIX}${scope}:${hash}`;

  try {
    const res = await redis.set(key, "1", "EX", ttlSeconds, "NX");
    return res === "OK";
  } catch {
    return true; // error transitorio: fail-open
  }
}
