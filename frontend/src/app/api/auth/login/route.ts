import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { fetchGraphQL } from "@/lib/graphql-client";
import { LOGIN_MUTATION } from "@/lib/auth/mutations";
import { loginSchema } from "@/lib/validation/auth";
import {
  AUTH_COOKIE,
  REFRESH_COOKIE,
  REFRESH_TOKEN_MAX_AGE,
} from "@/lib/auth/constants";
import {
  sessionCookieOptions,
  refreshCookieOptions,
} from "@/lib/security/cookies";
import { getTokenMaxAgeSeconds } from "@/lib/auth/jwt";
import { assertAllowedOrigin } from "@/lib/security/origin";
import type { LoginResponse } from "@/types/auth";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/login
 * Autentica contra WordPress y fija las cookies httpOnly de sesión.
 * Los tokens NUNCA se devuelven en el cuerpo (solo se exponen como cookies).
 */
export async function POST(request: Request) {
  if (!assertAllowedOrigin(request)) {
    return NextResponse.json({ error: "Origen no permitido." }, { status: 403 });
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = loginSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  let data: LoginResponse;
  try {
    data = await fetchGraphQL<LoginResponse>(LOGIN_MUTATION, {
      variables: parsed.data,
      revalidate: 0,
    });
  } catch {
    // No distinguimos usuario inexistente de contraseña incorrecta.
    return NextResponse.json({ error: "Credenciales inválidas." }, { status: 401 });
  }

  const { authToken, refreshToken, user } = data.login;
  if (!authToken || !refreshToken) {
    return NextResponse.json(
      { error: "Respuesta de autenticación incompleta." },
      { status: 502 },
    );
  }

  const store = await cookies();
  store.set(AUTH_COOKIE, authToken, sessionCookieOptions(getTokenMaxAgeSeconds(authToken)));
  store.set(REFRESH_COOKIE, refreshToken, refreshCookieOptions(REFRESH_TOKEN_MAX_AGE));

  return NextResponse.json({ user }, { status: 200 });
}
