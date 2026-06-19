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
import { sessionCookieOptions, refreshCookieOptions } from "@/lib/security/cookies";
import { getTokenMaxAgeSeconds } from "@/lib/auth/jwt";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";
import type { LoginResponse } from "@/types/auth";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/login
 * Autentica contra WordPress y fija las cookies httpOnly de sesión.
 * Los tokens NUNCA se devuelven en el cuerpo (solo se exponen como cookies).
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "login", limit: 5, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    logger.warn({ event: "auth.login.invalid_json" }, "JSON inválido en login");
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = loginSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn({ event: "auth.login.validation_error" }, "Validación fallida en login");
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
    logger.info({ event: "auth.login.failed" }, "Credenciales inválidas en login");
    return NextResponse.json({ error: "Credenciales inválidas." }, { status: 401 });
  }

  const { authToken, refreshToken, user } = data.login;
  if (!authToken || !refreshToken) {
    logger.error({ event: "auth.login.incomplete_response" }, "WP devolvió login incompleto");
    return NextResponse.json(
      { error: "Respuesta de autenticación incompleta." },
      { status: 502 },
    );
  }

  const store = await cookies();
  store.set(
    AUTH_COOKIE,
    authToken,
    sessionCookieOptions(getTokenMaxAgeSeconds(authToken)),
  );
  store.set(REFRESH_COOKIE, refreshToken, refreshCookieOptions(REFRESH_TOKEN_MAX_AGE));

  logger.info({ event: "auth.login.success", userId: user.id }, "Login exitoso");
  return NextResponse.json({ user }, { status: 200 });
}
