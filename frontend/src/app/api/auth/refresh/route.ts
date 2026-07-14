import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { fetchGraphQL } from "@/lib/graphql-client";
import { REFRESH_TOKEN_MUTATION } from "@/lib/auth/mutations";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";
import { sessionCookieOptions, expiredCookieOptions } from "@/lib/security/cookies";
import { getTokenMaxAgeSeconds } from "@/lib/auth/jwt";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";
import type { RefreshResponse } from "@/types/auth";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/refresh
 * Intercambia el refresh token (cookie) por un nuevo JWT de acceso.
 * Endpoint explícito; el middleware también refresca de forma transparente.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "refresh", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  const store = await cookies();
  const refreshToken = store.get(REFRESH_COOKIE)?.value;
  if (!refreshToken) {
    logger.warn({ event: "auth.refresh.no_token" }, "Intento de refresh sin token");
    return NextResponse.json({ error: "Sin sesión." }, { status: 401 });
  }

  let data: RefreshResponse;
  try {
    data = await fetchGraphQL<RefreshResponse>(REFRESH_TOKEN_MUTATION, {
      variables: { refreshToken },
      revalidate: 0,
    });
  } catch {
    logger.warn(
      { event: "auth.refresh.invalid" },
      "Refresh token inválido o revocado, cookies limpiadas",
    );
    // Refresh inválido/revocado: limpiamos cookies para forzar re-login.
    store.set(AUTH_COOKIE, "", expiredCookieOptions("/"));
    store.set(REFRESH_COOKIE, "", expiredCookieOptions("/"));
    return NextResponse.json({ error: "Sesión expirada." }, { status: 401 });
  }

  const authToken = data.refreshJwtAuthToken.authToken;
  if (!authToken) {
    logger.warn(
      { event: "auth.refresh.no_token" },
      "No se pudo obtener nuevo auth token",
    );
    return NextResponse.json({ error: "No se pudo refrescar." }, { status: 502 });
  }

  store.set(
    AUTH_COOKIE,
    authToken,
    sessionCookieOptions(getTokenMaxAgeSeconds(authToken)),
  );

  logger.info({ event: "auth.refresh.success" }, "Token refrescado correctamente");
  return NextResponse.json({ ok: true }, { status: 200 });
}
