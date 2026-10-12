import "server-only";
import { getRedis } from "@/lib/redis/client";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Idempotencia para operaciones críticas (checkout): evita pedidos duplicados
//  por doble clic o reintentos de red.
//
//  El cliente envía una cabecera `Idempotency-Key` (UUID). La primera petición
//  reserva la clave (estado "processing"); al completarse, se guarda la
//  respuesta. Reintentos con la misma clave reciben la respuesta cacheada
//  (replay) o un 409 si la primera sigue en curso.
//
//  Qué pasa cuando Redis falla (la propiedad que NO se negocia es la primera):
//  · Un duplicado YA DETECTADO nunca vuelve a proceder. Si `SET NX` confirma que
//    la clave existe y la lectura posterior falla o devuelve algo ilegible, se
//    responde `conflict` (reintentable), no `proceed`: `proceed` crearía el
//    segundo pedido justo cuando Redis ya demostró que el primero existe.
//  · Solo el fallo del `SET NX` inicial, o no tener Redis, deja pasar: ahí no
//    sabemos si hay duplicado, y bloquear todo el checkout por una caída de
//    Redis cuesta más que el riesgo de un doble envío. Se registra siempre.
// ============================================================================

const PREFIX = "idem:";
const DEFAULT_TTL = 60 * 60 * 24; // 24h

export type IdempotencyState =
  | { status: "proceed" }
  | { status: "replay"; body: unknown; statusCode: number }
  | { status: "conflict" };

/** Un fallo de Redis degrada la idempotencia, pero nunca en silencio. */
function logRedisError(op: string, error: unknown): void {
  logger.error(
    {
      event: "idempotency.redis_error",
      op,
      err: error instanceof Error ? error.message : String(error),
    },
    "Fallo de Redis en la idempotencia del checkout",
  );
}

/** Valida el formato de la clave (longitud razonable). */
export function isValidIdempotencyKey(key: string | null): key is string {
  return !!key && key.length >= 8 && key.length <= 200;
}

/**
 * Reserva la clave de idempotencia. Devuelve:
 *  - `proceed`  : primera vez, continúa la operación.
 *  - `replay`   : ya completada, devuelve la respuesta guardada.
 *  - `conflict` : una petición con la misma clave sigue en curso.
 */
export async function reserveIdempotencyKey(
  key: string,
  ttlSeconds: number = DEFAULT_TTL,
): Promise<IdempotencyState> {
  const redis = getRedis();
  if (!redis) {
    // Sin Redis no podemos garantizar idempotencia: dejamos pasar.
    return { status: "proceed" };
  }

  const redisKey = PREFIX + key;

  try {
    // SET NX: solo reserva si la clave no existía.
    const reserved = await redis.set(
      redisKey,
      JSON.stringify({ state: "processing" }),
      "EX",
      ttlSeconds,
      "NX",
    );

    if (reserved === "OK") {
      return { status: "proceed" };
    }

    // La clave ya existía: ¿completada o en curso?
    try {
      const existing = await redis.get(redisKey);
      if (existing) {
        const parsed = JSON.parse(existing) as {
          state: string;
          body?: unknown;
          statusCode?: number;
        };
        if (parsed.state === "done") {
          return {
            status: "replay",
            body: parsed.body,
            statusCode: parsed.statusCode ?? 200,
          };
        }
      }
      return { status: "conflict" };
    } catch (error) {
      // El SET NX ya confirmó el duplicado: `conflict` (reintentable), nunca `proceed`.
      logRedisError("read_duplicate", error);
      return { status: "conflict" };
    }
  } catch (error) {
    // Falló el propio SET NX: no sabemos si hay duplicado (ver cabecera).
    logRedisError("reserve", error);
    return { status: "proceed" };
  }
}

/** Guarda la respuesta final asociada a la clave (para replays posteriores). */
export async function storeIdempotentResult(
  key: string,
  body: unknown,
  statusCode: number,
  ttlSeconds: number = DEFAULT_TTL,
): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  try {
    await redis.set(
      PREFIX + key,
      JSON.stringify({ state: "done", body, statusCode }),
      "EX",
      ttlSeconds,
    );
  } catch (error) {
    // Mejor esfuerzo: sin el resultado guardado, un reintento verá `conflict` hasta el TTL.
    logRedisError("store", error);
  }
}

/** Libera la reserva si la operación falló (permite reintentar). */
export async function releaseIdempotencyKey(key: string): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  try {
    await redis.del(PREFIX + key);
  } catch (error) {
    // Mejor esfuerzo: si no se libera, el reintento verá `conflict` hasta el TTL.
    logRedisError("release", error);
  }
}
