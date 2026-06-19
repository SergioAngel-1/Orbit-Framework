import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";
import { expiredCookieOptions } from "@/lib/security/cookies";
import { guardMutation } from "@/lib/api/guard";
import { revokeToken } from "@/lib/auth/revocation";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/logout
 * Revoca el JWT de acceso actual (blocklist en Redis hasta su `exp`) y borra las
 * cookies de sesión, de modo que el token deje de servir aunque se hubiera
 * copiado. Para invalidación global usar /api/auth/logout-all.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "logout", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  const store = await cookies();

  // Revocar el token de acceso vigente antes de borrar la cookie.
  const currentToken = store.get(AUTH_COOKIE)?.value;
  if (currentToken) {
    await revokeToken(currentToken);
  }

  store.set(AUTH_COOKIE, "", expiredCookieOptions("/"));
  store.set(REFRESH_COOKIE, "", expiredCookieOptions("/"));

  logger.info({ event: "auth.logout" }, "Sesión cerrada correctamente");

  return NextResponse.json({ ok: true }, { status: 200 });
}
