import "server-only";
import crypto from "node:crypto";
import { decodeJwt } from "jose";
import { getRedis } from "@/lib/redis/client";

// ============================================================================
//  Revocación de tokens de acceso (blocklist).
//
//  El logout ahora INVALIDA de verdad el JWT de acceso: guardamos un hash del
//  token en Redis con TTL = tiempo restante hasta `exp`. `getSession` consulta
//  esta lista y rechaza tokens revocados aunque su firma siga siendo válida.
//
//  Limitación consciente (fail-open): si Redis no está disponible no podemos
//  consultar la lista; preferimos no tumbar el sitio (un token revocado podría
//  pasar hasta su `exp`, normalmente 5 min). Documentado en el plan.
// ============================================================================

const PREFIX = "revoked:";

/** Hash estable del token (no guardamos el JWT en claro). */
function tokenKey(token: string): string {
  return PREFIX + crypto.createHash("sha256").update(token).digest("hex");
}

/** Segundos restantes hasta la expiración del token (mínimo 1). */
function remainingTtl(token: string): number {
  try {
    const { exp } = decodeJwt(token);
    if (typeof exp === "number") {
      const secs = exp - Math.floor(Date.now() / 1000);
      if (secs > 0) return secs;
    }
  } catch {
    /* token no decodificable */
  }
  return 0;
}

/** Marca un token de acceso como revocado hasta su expiración natural. */
export async function revokeToken(token: string): Promise<void> {
  const redis = getRedis();
  if (!redis) return;
  const ttl = remainingTtl(token);
  if (ttl <= 0) return; // ya expirado: nada que revocar
  try {
    await redis.set(tokenKey(token), "1", "EX", ttl);
  } catch {
    /* mejor esfuerzo */
  }
}

/** ¿El token está en la blocklist? Fail-open (false) si Redis no responde. */
export async function isTokenRevoked(token: string): Promise<boolean> {
  const redis = getRedis();
  if (!redis) return false;
  try {
    return (await redis.exists(tokenKey(token))) === 1;
  } catch {
    return false;
  }
}
