import "server-only";
import { getRedis } from "@/lib/redis/client";

// ============================================================================
//  Rate limiting por ventana fija con Redis (INCR + EXPIRE).
//
//  Diseño: el rate-limit es una MITIGACIÓN, no una barrera de integridad. Si
//  Redis no está disponible, degradamos a "permitir" (fail-open) para no tumbar
//  el servicio; se registra el aviso a nivel de conexión.
// ============================================================================

export interface RateLimitResult {
  success: boolean;
  /** Peticiones restantes en la ventana actual. */
  remaining: number;
  /** Segundos hasta que se reinicia la ventana (para Retry-After). */
  retryAfter: number;
  /** Límite configurado. */
  limit: number;
}

/**
 * Aplica un límite de `limit` peticiones por `windowSeconds` al `identifier`.
 *
 * @param identifier Clave única (p. ej. `login:1.2.3.4`).
 */
export async function rateLimit(
  identifier: string,
  limit: number,
  windowSeconds: number,
): Promise<RateLimitResult> {
  const redis = getRedis();
  if (!redis) {
    // Sin Redis: no limitamos (fail-open).
    return { success: true, remaining: limit, retryAfter: 0, limit };
  }

  const key = `rl:${identifier}`;

  try {
    const count = await redis.incr(key);
    if (count === 1) {
      await redis.expire(key, windowSeconds);
    }

    if (count > limit) {
      const ttl = await redis.ttl(key);
      return {
        success: false,
        remaining: 0,
        retryAfter: ttl > 0 ? ttl : windowSeconds,
        limit,
      };
    }

    return { success: true, remaining: limit - count, retryAfter: 0, limit };
  } catch {
    // Error transitorio de Redis: fail-open.
    return { success: true, remaining: limit, retryAfter: 0, limit };
  }
}
