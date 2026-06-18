import "server-only";
import { getRedis } from "@/lib/redis/client";

// ============================================================================
//  Idempotencia para operaciones críticas (checkout): evita pedidos duplicados
//  por doble clic o reintentos de red.
//
//  El cliente envía una cabecera `Idempotency-Key` (UUID). La primera petición
//  reserva la clave (estado "processing"); al completarse, se guarda la
//  respuesta. Reintentos con la misma clave reciben la respuesta cacheada
//  (replay) o un 409 si la primera sigue en curso.
// ============================================================================

const PREFIX = "idem:";
const DEFAULT_TTL = 60 * 60 * 24; // 24h

export type IdempotencyState =
  | { status: "proceed" }
  | { status: "replay"; body: unknown; statusCode: number }
  | { status: "conflict" };

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
  } catch {
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
  } catch {
    /* mejor esfuerzo */
  }
}

/** Libera la reserva si la operación falló (permite reintentar). */
export async function releaseIdempotencyKey(key: string): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  try {
    await redis.del(PREFIX + key);
  } catch {
    /* mejor esfuerzo */
  }
}
