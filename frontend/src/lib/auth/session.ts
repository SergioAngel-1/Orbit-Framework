import "server-only";
import { cookies } from "next/headers";
import { cache } from "react";
import { logger } from "@/lib/observability/logger";
import { AUTH_COOKIE } from "./constants";
import { verifyAuthToken } from "./jwt";
import { isTokenRevoked } from "./revocation";
import type { Session } from "@/types/auth";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

// ============================================================================
//  Acceso a la sesión desde Server Components y Route Handlers.
//  `cache()` memoiza el resultado durante el ciclo de vida del request, así no
//  re-verificamos el JWT varias veces en el mismo render.
// ============================================================================

/** Devuelve el JWT de acceso crudo desde la cookie, o null. */
export const getAuthToken = cache(async (): Promise<string | null> => {
  const store = await cookies();
  return store.get(AUTH_COOKIE)?.value ?? null;
});

/**
 * Devuelve la sesión verificada (firma + vigencia del JWT) o null.
 * No lanza: pensado para lógica de UI condicional.
 */
export const getSession = cache(async (): Promise<Session | null> => {
  const token = await getAuthToken();
  if (!token) {
    return null;
  }
  const payload = await verifyAuthToken(token);
  if (!payload) {
    return null;
  }
  // Revocación: un token en la blocklist (logout) se rechaza aunque su firma
  // siga siendo válida. Fail-open si Redis no responde.
  if (await isTokenRevoked(token)) {
    return null;
  }
  return { userId: payload.userId, token };
});

/**
 * Exige sesión. Lanza si no hay usuario autenticado: úsalo en Route Handlers /
 * páginas protegidas (combínalo con redirección a /login donde proceda).
 */
export async function requireSession(): Promise<Session> {
  const session = await getSession();
  if (!session) {
    throw new Error("UNAUTHENTICATED");
  }
  return session;
}

/**
 * Rota el secreto JWT del usuario en WordPress: invalida TODOS sus refresh tokens,
 * también el de este dispositivo (los access tokens vivos caducan solos, ~5 min).
 * Quien llame y quiera conservar la sesión actual debe reemitirla después.
 *
 * @returns `true` si WordPress lo confirmó. Un fallo se registra y devuelve `false`
 * en vez de lanzar, pero el llamador DEBE tenerlo en cuenta: darlo por hecho dejaría
 * al usuario creyendo que cerró sus otras sesiones cuando no es así.
 */
export async function revokeAllSessions(accessToken: string): Promise<boolean> {
  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/logout-all`, {
      method: "POST",
      headers: { Authorization: `Bearer ${accessToken}` },
      cache: "no-store",
    });
    if (!res.ok) {
      logger.error(
        { event: "auth.revoke_all_sessions.wp_error", status: res.status },
        "WordPress no pudo revocar las sesiones",
      );
      return false;
    }
    return true;
  } catch (error) {
    logger.error(
      {
        event: "auth.revoke_all_sessions.error",
        err: error instanceof Error ? error.message : String(error),
      },
      "No se pudo contactar con WordPress para revocar las sesiones",
    );
    return false;
  }
}
