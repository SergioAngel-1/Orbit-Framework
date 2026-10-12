import "server-only";
import crypto from "node:crypto";
import { getRedis } from "@/lib/redis/client";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Login pendiente de verificación 2FA.
//
//  Antes, el paso intermedio entre "contraseña correcta" y "2FA verificado"
//  viajaba al navegador como un JWT FIRMADO (no cifrado) con el authToken y el
//  refreshToken reales en su payload. Un JWT firmado no oculta su contenido:
//  cualquiera podía decodificar el payload en base64 sin conocer el secreto y
//  obtener la sesión completa, saltándose el segundo factor por completo.
//
//  Ahora las credenciales reales se guardan aquí, en el servidor, y el cliente
//  solo recibe un id aleatorio sin información para reclamarlas tras verificar
//  el TOTP o el código de recuperación.
//
//  · Fail-CLOSED (a diferencia de rate-limit o idempotencia): sin Redis no hay
//    dónde guardar las credenciales con seguridad, así que no se emite el login
//    pendiente en vez de arriesgar volver a filtrar tokens en claro.
//  · Un código mal tecleado se reintenta con el mismo id (`getPendingLogin` no lo
//    consume), pero con un TOPE de intentos por id: con la contraseña correcta,
//    un TOTP de 6 dígitos podría adivinarse dentro de los 5 min de vida rotando
//    de IP, y el rate-limit por IP no lo frena.
//  · Un solo uso REAL: la sesión se reclama con `consumePendingLogin`, un GETDEL
//    atómico. Con un get y un del por separado, dos peticiones simultáneas con un
//    código válido podían crear dos sesiones.
// ============================================================================

const PREFIX = "pending_login:";
const ATTEMPTS_PREFIX = "pending_login_attempts:";
/** Vida del login pendiente: el margen que tenía el JWT efímero anterior. */
const TTL_SECONDS = 300;
/** Códigos 2FA incorrectos que se toleran antes de invalidar el login pendiente. */
export const PENDING_LOGIN_MAX_ATTEMPTS = 5;

export interface PendingLogin {
  authToken: string;
  refreshToken: string;
  userId: string;
}

function isPendingLogin(value: unknown): value is PendingLogin {
  if (value === null || typeof value !== "object") return false;
  const v = value as Record<string, unknown>;
  return [v.authToken, v.refreshToken, v.userId].every(
    (field) => typeof field === "string" && field.length > 0,
  );
}

/** Un fallo de Redis degrada a "no hay login pendiente", pero deja rastro. */
function logRedisError(op: string, error: unknown): void {
  logger.error(
    {
      event: "auth.pending_login.redis_error",
      op,
      err: error instanceof Error ? error.message : String(error),
    },
    "Fallo de Redis en el login pendiente de 2FA",
  );
}

function parse(raw: string | null): PendingLogin | null {
  if (!raw) return null;
  try {
    const value: unknown = JSON.parse(raw);
    return isPendingLogin(value) ? value : null;
  } catch {
    return null;
  }
}

/** Guarda una sesión pendiente de 2FA y devuelve su id opaco, o `null` si no hay Redis. */
export async function createPendingLogin(data: PendingLogin): Promise<string | null> {
  const redis = getRedis();
  if (!redis) return null;
  const id = crypto.randomUUID();
  try {
    await redis.set(PREFIX + id, JSON.stringify(data), "EX", TTL_SECONDS);
    return id;
  } catch (error) {
    logRedisError("create", error);
    return null;
  }
}

/**
 * Lee la sesión pendiente asociada a `id` SIN borrarla: un código incorrecto
 * debe poder reintentarse con el mismo id hasta que expire, se agote el tope de
 * intentos o lo frene el rate-limit.
 */
export async function getPendingLogin(id: string): Promise<PendingLogin | null> {
  const redis = getRedis();
  if (!redis) return null;
  try {
    return parse(await redis.get(PREFIX + id));
  } catch (error) {
    logRedisError("get", error);
    return null;
  }
}

/**
 * Reclama la sesión pendiente: la lee y la borra de forma atómica, así que de dos
 * llamadas simultáneas solo una recibe las credenciales. Llamar SOLO tras
 * verificar el segundo factor con éxito, y no abrir sesión si devuelve `null`.
 */
export async function consumePendingLogin(id: string): Promise<PendingLogin | null> {
  const redis = getRedis();
  if (!redis) return null;
  try {
    const raw = await redis.getdel(PREFIX + id);
    await redis.del(ATTEMPTS_PREFIX + id);
    return parse(raw);
  } catch (error) {
    logRedisError("consume", error);
    return null;
  }
}

/**
 * Registra un código 2FA incorrecto. Devuelve `true` cuando se agotó el cupo (y
 * el login pendiente queda invalidado: hay que volver a iniciar sesión) y
 * `false` mientras aún quedan intentos. Sin Redis falla cerrado (`true`).
 */
export async function recordFailedAttempt(id: string): Promise<boolean> {
  const redis = getRedis();
  if (!redis) return true;
  try {
    const key = ATTEMPTS_PREFIX + id;
    const attempts = await redis.incr(key);
    await redis.expire(key, TTL_SECONDS);
    if (attempts < PENDING_LOGIN_MAX_ATTEMPTS) return false;
    await redis.del(PREFIX + id, key);
    return true;
  } catch (error) {
    logRedisError("record_attempt", error);
    return true;
  }
}
