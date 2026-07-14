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

// ----------------------------------------------------------------------------
//  Fallback en memoria (por instancia) para endpoints CRÍTICOS (auth) cuando
//  Redis no está disponible. No es distribuido, pero evita que la caída de Redis
//  desactive POR COMPLETO el límite en login/2FA/registro (antes: fail-open).
// ----------------------------------------------------------------------------
const memoryWindows = new Map<string, { count: number; resetAt: number }>();

function memoryRateLimit(
  identifier: string,
  limit: number,
  windowSeconds: number,
): RateLimitResult {
  const now = Date.now();
  const key = `mem:${identifier}`;
  const entry = memoryWindows.get(key);

  if (!entry || entry.resetAt <= now) {
    memoryWindows.set(key, { count: 1, resetAt: now + windowSeconds * 1000 });
    if (memoryWindows.size > 5000) {
      for (const [k, v] of memoryWindows) if (v.resetAt <= now) memoryWindows.delete(k);
    }
    return { success: true, remaining: limit - 1, retryAfter: 0, limit };
  }

  entry.count += 1;
  if (entry.count > limit) {
    return {
      success: false,
      remaining: 0,
      retryAfter: Math.ceil((entry.resetAt - now) / 1000),
      limit,
    };
  }
  return { success: true, remaining: limit - entry.count, retryAfter: 0, limit };
}

export interface RateLimitOptions {
  /**
   * Si Redis no está disponible, en vez de fail-open usa un contador EN MEMORIA
   * (por instancia). Recomendado para auth (login/2FA/registro): así un fallo de
   * Redis no elimina el límite por completo.
   */
  strict?: boolean;
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
  options: RateLimitOptions = {},
): Promise<RateLimitResult> {
  const redis = getRedis();
  if (!redis) {
    // Sin Redis: en modo estricto degradamos a memoria; si no, fail-open.
    return options.strict
      ? memoryRateLimit(identifier, limit, windowSeconds)
      : { success: true, remaining: limit, retryAfter: 0, limit };
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
    // Error transitorio de Redis: estricto → memoria; si no, fail-open.
    return options.strict
      ? memoryRateLimit(identifier, limit, windowSeconds)
      : { success: true, remaining: limit, retryAfter: 0, limit };
  }
}
