import "server-only";
import { getRedis } from "@/lib/redis/client";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Secreto TOTP pendiente de activación (entre POST /2fa/setup y
//  GET /2fa/qr + POST /2fa/activate).
//
//  Antes el secreto viajaba a un tercero (api.qrserver.com) dentro de la URL del
//  QR, con lo que quedaba en sus logs y en el historial del navegador, lo que
//  anulaba la garantía del segundo factor. Después pasó al query string de
//  nuestro propio GET /2fa/qr, que seguía filtrándolo en logs de servidor y de
//  proxy, en el historial y en la cabecera Referer.
//
//  Ahora POST /2fa/setup lo guarda aquí, en el servidor, con TTL, y GET /2fa/qr
//  lo lee por sesión: el cliente ya no puede inyectar (ni filtrar) el secreto
//  vía URL.
//
//  Asimetría deliberada con login pendiente (`pending-login.ts`):
//   · Guardar es BEST-EFFORT: sin Redis el alta no se rompe; simplemente no hay
//     QR (404) y la interfaz cae al código manual que ya muestra. No se pierde
//     seguridad: el QR es una comodidad, el secreto que se activa lo verifica
//     igualmente `activate`.
//   · Leer es FAIL-CLOSED: sin Redis o sin entrada, no hay QR que mostrar, en
//     vez de arriesgar un secreto inventado o ausente.
//  Los fallos de Redis se registran (sin el secreto) en lugar de tragarse.
// ============================================================================

const PREFIX = "2fa_pending:";
/** Vida del secreto pendiente: tiempo razonable para completar el alta. */
const TTL_SECONDS = 900;

function pendingKey(userId: string): string {
  return `${PREFIX}${userId}`;
}

function logRedisError(op: string, error: unknown): void {
  // Nunca se registra el secreto, ni la clave (lleva el id de usuario).
  logger.error(
    {
      event: "auth.2fa_pending.redis_error",
      op,
      err: error instanceof Error ? error.message : String(error),
    },
    "Fallo de Redis con el secreto 2FA pendiente",
  );
}

/** Guarda el secreto TOTP recién generado, pendiente de activación. */
export async function setPending2faSecret(
  userId: string,
  secret: string,
): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  try {
    await redis.set(pendingKey(userId), secret, "EX", TTL_SECONDS);
  } catch (error) {
    logRedisError("set", error);
  }
}

/** Lee el secreto TOTP pendiente del usuario, o `null` si no hay ninguno vigente. */
export async function getPending2faSecret(userId: string): Promise<string | null> {
  const redis = getRedis();
  if (!redis) return null;
  try {
    return await redis.get(pendingKey(userId));
  } catch (error) {
    logRedisError("get", error);
    return null;
  }
}

/**
 * Invalida el secreto pendiente del usuario.
 *
 * Debe llamarse en cuanto el secreto deja de estar "pendiente": tras activarlo
 * con éxito (POST /2fa/activate) pasa a ser el secreto ACTIVO, y `GET /2fa/qr`
 * no debe poder seguir sirviéndolo como QR escaneable durante el resto del TTL.
 * También al desactivar 2FA, por si quedó un intento de alta abandonado.
 */
export async function delPending2faSecret(userId: string): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  try {
    await redis.del(pendingKey(userId));
  } catch (error) {
    logRedisError("del", error);
  }
}
