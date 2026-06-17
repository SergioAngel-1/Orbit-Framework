import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { fetchGraphQL } from "@/lib/graphql-client";
import { REGISTER_USER_MUTATION, LOGIN_MUTATION } from "@/lib/auth/mutations";
import { registerSchema } from "@/lib/validation/auth";
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
import type { RegisterResponse, LoginResponse } from "@/types/auth";

export const dynamic = "force-dynamic";

/**
 * POST /api/auth/register
 * Crea un usuario (requiere registro habilitado en WordPress) y, si tiene éxito,
 * inicia sesión automáticamente fijando las cookies httpOnly.
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

  const parsed = registerSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  // 1) Crear el usuario.
  try {
    await fetchGraphQL<RegisterResponse>(REGISTER_USER_MUTATION, {
      variables: parsed.data,
      revalidate: 0,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message.toLowerCase() : "";
    const exists =
      message.includes("exist") ||
      message.includes("registrado") ||
      message.includes("already");
    return NextResponse.json(
      { error: exists ? "El usuario o email ya existe." : "No se pudo registrar." },
      { status: exists ? 409 : 400 },
    );
  }

  // 2) Auto-login para dejar la sesión iniciada.
  try {
    const data = await fetchGraphQL<LoginResponse>(LOGIN_MUTATION, {
      variables: { username: parsed.data.username, password: parsed.data.password },
      revalidate: 0,
    });
    const { authToken, refreshToken, user } = data.login;
    const store = await cookies();
    store.set(AUTH_COOKIE, authToken, sessionCookieOptions(getTokenMaxAgeSeconds(authToken)));
    store.set(REFRESH_COOKIE, refreshToken, refreshCookieOptions(REFRESH_TOKEN_MAX_AGE));
    return NextResponse.json({ user }, { status: 201 });
  } catch {
    // Usuario creado pero el auto-login falló: el cliente puede iniciar sesión.
    return NextResponse.json(
      { ok: true, message: "Usuario creado. Inicia sesión." },
      { status: 201 },
    );
  }
}
