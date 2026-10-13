import "server-only";
import crypto from "node:crypto";
import { getRedis } from "@/lib/redis/client";
import { logRedisFailure } from "@/lib/redis/log";

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
  } catch (error) {
    // Error transitorio: fail-open. El anti-replay se pierde, y por eso es `error`.
    logRedisFailure("replay", "mark", error, {
      level: "error",
      effect: "sin anti-replay mientras dure",
    });
    return true;
  }
}

/**
 * Suelta la marca de un evento: el siguiente intento con el mismo cuerpo vuelve
 * a procesarse. Para todo camino que NO deja el evento resuelto (fallo
 * transitorio, respuesta no-2xx que la pasarela reintentará): si no, el
 * reintento se descartaría como duplicado y el cobro no se aplicaría jamás.
 */
export async function releaseEvent(scope: string, rawBody: string): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  const hash = crypto.createHash("sha256").update(rawBody).digest("hex");
  try {
    await redis.del(`${PREFIX}${scope}:${hash}`);
  } catch (error) {
    // Sin poder soltarla, el reintento caerá en «duplicado» hasta que caduque el
    // TTL; el pedido sigue en su estado y se puede conciliar a mano.
    logRedisFailure("replay", "release", error, {
      level: "error",
      effect:
        "el reintento de la pasarela se descartará como duplicado hasta que caduque",
    });
  }
}
