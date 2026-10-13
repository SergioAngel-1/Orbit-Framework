import "server-only";
import { getRedis } from "@/lib/redis/client";
import { logRedisFailure } from "@/lib/redis/log";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Lock distribuido ligero (Redis SET NX) para serializar operaciones de
//  lectura-modificación-escritura que de otro modo se pisarían (lost update).
//
//  Caso de uso: wishlist y direcciones se guardan en `customer.meta_data` vía
//  wc/v3 (leer cliente → mutar array → PUT). Dos peticiones concurrentes del
//  mismo usuario (doble pestaña, doble clic) pueden perder cambios. Envolviendo
//  la sección crítica con un lock por-usuario garantizamos exclusión mutua.
//
//  Diseño fail-open (coherente con rate-limit/idempotencia): si Redis no está
//  disponible NO bloqueamos la operación; simplemente perdemos la garantía de
//  serialización, que es una mitigación, no una barrera de integridad.
// ============================================================================

const LOCK_PREFIX = "lock:";
const DEFAULT_TTL_MS = 5000; // vida máxima del lock (evita bloqueos colgados)
const RETRY_DELAY_MS = 50;
const MAX_WAIT_MS = 3000; // tiempo máximo esperando a adquirir el lock

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Ejecuta `fn` en exclusión mutua para `key`. Si no hay Redis, ejecuta `fn`
 * directamente (sin garantía de serialización). Si no logra adquirir el lock
 * dentro de `MAX_WAIT_MS`, ejecuta igualmente (mejor degradar que bloquear al
 * usuario), registrando que la sección no quedó serializada.
 *
 * @param key Identificador lógico, p. ej. `wishlist:42` o `addresses:42`.
 */
export async function withLock<T>(
  key: string,
  fn: () => Promise<T>,
  ttlMs: number = DEFAULT_TTL_MS,
): Promise<T> {
  const redis = getRedis();
  if (!redis) {
    return fn();
  }

  const redisKey = `${LOCK_PREFIX}${key}`;
  const token = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  const deadline = Date.now() + MAX_WAIT_MS;
  let acquired = false;

  while (Date.now() < deadline) {
    try {
      const res = await redis.set(redisKey, token, "PX", ttlMs, "NX");
      if (res === "OK") {
        acquired = true;
        break;
      }
    } catch (error) {
      // Redis intermitente: degradamos a sin-lock.
      logRedisFailure("lock", "acquire", error, {
        effect: "se ejecuta sin exclusión mutua",
      });
      return fn();
    }
    await sleep(RETRY_DELAY_MS);
  }

  if (!acquired) {
    // Otro proceso lo retuvo todo el plazo: se ejecuta igualmente (mejor degradar
    // que bloquear al usuario), pero la sección no quedó serializada.
    logger.warn(
      { event: "lock.wait_timeout", key },
      "No se obtuvo el lock a tiempo: la sección crítica se ejecuta sin serializar",
    );
  }

  try {
    return await fn();
  } finally {
    if (acquired) {
      // Liberación segura: solo borra si el token sigue siendo el nuestro
      // (evita liberar un lock readquirido por otra petición tras expirar).
      try {
        const release =
          "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
        await redis.eval(release, 1, redisKey, token);
      } catch (error) {
        // Mejor esfuerzo: el TTL liberará el lock igualmente.
        logRedisFailure("lock", "release", error, {
          effect: "el lock caduca por su TTL",
        });
      }
    }
  }
}
