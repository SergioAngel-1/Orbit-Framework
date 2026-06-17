import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { fetchGraphQL } from "@/lib/graphql-client";
import { REFRESH_TOKEN_MUTATION } from "@/lib/auth/mutations";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";
import {
  sessionCookieOptions,
  expiredCookieOptions,
} from "@/lib/security/cookies";
import { getTokenMaxAgeSeconds } from "@/lib/auth/jwt";
import { assertAllowedOrigin } from "@/lib/security/origin";
import type { RefreshResponse } from "@/types/auth";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/refresh
 * Intercambia el refresh token (cookie) por un nuevo JWT de acceso.
 * Endpoint explícito; el middleware también refresca de forma transparente.
 */
export async function POST(request: Request) {
  if (!assertAllowedOrigin(request)) {
    return NextResponse.json({ error: "Origen no permitido." }, { status: 403 });
  }

  const store = await cookies();
  const refreshToken = store.get(REFRESH_COOKIE)?.value;
  if (!refreshToken) {
    return NextResponse.json({ error: "Sin sesión." }, { status: 401 });
  }

  let data: RefreshResponse;
  try {
    data = await fetchGraphQL<RefreshResponse>(REFRESH_TOKEN_MUTATION, {
      variables: { refreshToken },
      revalidate: 0,
    });
  } catch {
    // Refresh inválido/revocado: limpiamos cookies para forzar re-login.
    store.set(AUTH_COOKIE, "", expiredCookieOptions("/"));
    store.set(REFRESH_COOKIE, "", expiredCookieOptions("/"));
    return NextResponse.json({ error: "Sesión expirada." }, { status: 401 });
  }

  const authToken = data.refreshJwtAuthToken.authToken;
  if (!authToken) {
    return NextResponse.json({ error: "No se pudo refrescar." }, { status: 502 });
  }

  store.set(AUTH_COOKIE, authToken, sessionCookieOptions(getTokenMaxAgeSeconds(authToken)));
  return NextResponse.json({ ok: true }, { status: 200 });
}
