import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { SignJWT } from "jose";
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

const WP_INTERNAL =
  process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ??
  "http://wordpress:80";
const EPHEMERAL_SECRET = new TextEncoder().encode(
  process.env.GRAPHQL_JWT_AUTH_SECRET_KEY || "fallback-dev-secret-change-in-prod",
);

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "login", limit: 5, windowSeconds: 60, strict: true },
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
    logger.warn(
      { event: "auth.login.validation_error" },
      "Validación fallida en login",
    );
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
    logger.error(
      { event: "auth.login.incomplete_response" },
      "WP devolvió login incompleto",
    );
    return NextResponse.json(
      { error: "Respuesta de autenticación incompleta." },
      { status: 502 },
    );
  }

  // Check if 2FA is enabled for this user — fail-closed: any failure blocks login.
  let twoFactorEnabled: boolean;
  try {
    const statusRes = await fetch(
      `${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-status/${user.databaseId}`,
      {
        cache: "no-store",
        headers: { "X-HWE-Internal-Secret": process.env.HWE_REVALIDATION_SECRET ?? "" },
      },
    );
    if (!statusRes.ok) {
      logger.error(
        { event: "auth.login.2fa_check_error", httpStatus: statusRes.status },
        "Endpoint de estado 2FA devolvió error",
      );
      return NextResponse.json(
        { error: "Servicio de autenticación temporalmente no disponible." },
        { status: 503 },
      );
    }
    const statusData = (await statusRes.json()) as { enabled: boolean };
    twoFactorEnabled = statusData.enabled === true;
  } catch {
    logger.error(
      { event: "auth.login.2fa_check_failed" },
      "No se pudo verificar estado 2FA",
    );
    return NextResponse.json(
      { error: "Servicio de autenticación temporalmente no disponible." },
      { status: 503 },
    );
  }

  if (twoFactorEnabled) {
    // Create ephemeral token with the real tokens
    const ephemeralToken = await new SignJWT({
      authToken,
      refreshToken,
      userId: String(user.databaseId),
    })
      .setProtectedHeader({ alg: "HS256" })
      .setExpirationTime("5m")
      .setIssuedAt()
      .sign(EPHEMERAL_SECRET);

    logger.info({ event: "auth.login.2fa_required", userId: user.id }, "2FA requerido");
    return NextResponse.json({ requires_2fa: true, ephemeralToken }, { status: 200 });
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
