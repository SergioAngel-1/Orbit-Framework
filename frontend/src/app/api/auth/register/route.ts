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
import { sessionCookieOptions, refreshCookieOptions } from "@/lib/security/cookies";
import { getTokenMaxAgeSeconds } from "@/lib/auth/jwt";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";
import type { RegisterResponse, LoginResponse } from "@/types/auth";

export const dynamic = "force-dynamic";

const WP_INTERNAL =
  process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ??
  "http://wordpress:80";
const FRONTEND_URL = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";

/**
 * Dispara el email de verificación tras el registro (best-effort). La política
 * es OPCIONAL: NO bloquea el uso de la cuenta; solo inicia el flujo para que el
 * usuario pueda verificar cuando quiera (UI en /verify-email + reenvío). Un
 * fallo de envío no rompe el registro.
 */
async function sendVerificationEmail(authToken: string): Promise<void> {
  try {
    await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/send-verification`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${authToken}`,
      },
      body: JSON.stringify({ verification_url: `${FRONTEND_URL}/verify-email` }),
    });
  } catch (error) {
    logger.warn(
      {
        event: "auth.register.send_verification_failed",
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo enviar el email de verificación tras el registro",
    );
  }
}

/**
 * POST /api/auth/register
 * Crea un usuario (requiere registro habilitado en WordPress) y, si tiene éxito,
 * inicia sesión automáticamente fijando las cookies httpOnly.
 */
export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "register", limit: 5, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    logger.warn({ event: "auth.register.invalid_json" }, "JSON inválido en register");
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = registerSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "auth.register.validation_error" },
      "Validación fallida en register",
    );
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
    logger.warn({ event: "auth.register.failed", exists }, "Registro fallido");
    return NextResponse.json(
      { error: exists ? "El usuario o email ya existe." : "No se pudo registrar." },
      { status: exists ? 409 : 400 },
    );
  }

  logger.info(
    { event: "auth.register.success", username: parsed.data.username },
    "Usuario creado",
  );

  // 2) Auto-login para dejar la sesión iniciada.
  try {
    const data = await fetchGraphQL<LoginResponse>(LOGIN_MUTATION, {
      variables: { username: parsed.data.username, password: parsed.data.password },
      revalidate: 0,
    });
    const { authToken, refreshToken, user } = data.login;
    const store = await cookies();
    store.set(
      AUTH_COOKIE,
      authToken,
      sessionCookieOptions(getTokenMaxAgeSeconds(authToken)),
    );
    store.set(
      REFRESH_COOKIE,
      refreshToken,
      refreshCookieOptions(REFRESH_TOKEN_MAX_AGE),
    );
    logger.info(
      { event: "auth.register.auto_login", userId: user.id },
      "Auto-login post-registro exitoso",
    );

    // Inicia el flujo de verificación de email (opcional, no bloqueante).
    if (authToken) {
      await sendVerificationEmail(authToken);
    }

    return NextResponse.json({ user }, { status: 201 });
  } catch {
    logger.warn(
      { event: "auth.register.auto_login_failed" },
      "Usuario creado pero auto-login falló",
    );
    return NextResponse.json(
      { ok: true, message: "Usuario creado. Inicia sesión." },
      { status: 201 },
    );
  }
}
