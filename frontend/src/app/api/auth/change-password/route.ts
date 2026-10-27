import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
import { cookies } from "next/headers";
import { z } from "zod";
import { requireSession, revokeAllSessions } from "@/lib/auth/session";
import { revokeToken } from "@/lib/auth/revocation";
import { getTokenMaxAgeSeconds } from "@/lib/auth/jwt";
import { LOGIN_MUTATION } from "@/lib/auth/mutations";
import {
  AUTH_COOKIE,
  REFRESH_COOKIE,
  REFRESH_TOKEN_MAX_AGE,
} from "@/lib/auth/constants";
import { getCustomer } from "@/lib/account/data";
import { fetchGraphQL } from "@/lib/graphql-client";
import { sessionCookieOptions, refreshCookieOptions } from "@/lib/security/cookies";
import { guardMutation } from "@/lib/api/guard";
import { logger } from "@/lib/observability/logger";
import type { LoginResponse } from "@/types/auth";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

export const dynamic = "force-dynamic";

const changePasswordSchema = z.object({
  currentPassword: z.string().min(1, "Contraseña actual requerida").max(200),
  newPassword: z.string().min(8, "Mínimo 8 caracteres").max(200),
});

/**
 * Reemite la sesión de ESTE dispositivo con la contraseña nueva. Hace falta porque
 * cerrar las demás sesiones rota el secreto JWT del usuario, y eso invalida también
 * el refresh token de la sesión actual: sin esto, el usuario quedaría fuera en cuanto
 * caducase su access token (~5 min). Devuelve `false` si no se pudo (queda registrado).
 */
async function reissueCurrentSession(
  email: string,
  newPassword: string,
  oldAccessToken: string,
): Promise<boolean> {
  try {
    const data = await fetchGraphQL<LoginResponse>(LOGIN_MUTATION, {
      variables: { username: email, password: newPassword },
      revalidate: 0,
    });
    const { authToken, refreshToken } = data.login;
    if (!authToken || !refreshToken) throw new Error("login incompleto");

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
    // El access token anterior ya no sirve aunque aún no haya caducado.
    await revokeToken(oldAccessToken);
    return true;
  } catch (error) {
    logger.error(
      {
        event: "auth.change_password.reissue_failed",
        err: error instanceof Error ? error.message : String(error),
      },
      "No se pudo reemitir la sesión actual tras cambiar la contraseña",
    );
    return false;
  }
}

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "change_password", limit: 5, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  let body: unknown;
  try {
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = changePasswordSchema.safeParse(body);
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Datos inválidos.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  let session;
  try {
    session = await requireSession();
  } catch {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  // El email de la cuenta hace falta DESPUÉS, para reemitir esta sesión con la
  // contraseña nueva (`login` espera el email o el user_login, nunca el id numérico).
  // Se lee antes de cambiar nada: si falla, la contraseña no se toca.
  let email: string;
  try {
    email = ((await getCustomer()).email || "").trim();
  } catch (error) {
    logger.error({
      event: "auth.change_password.customer_error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json(
      { error: "No se pudo cambiar la contraseña." },
      { status: 502 },
    );
  }
  if (!email) {
    logger.warn({ event: "auth.change_password.no_email", userId: session.userId });
    return NextResponse.json(
      { error: "No se pudo cambiar la contraseña." },
      { status: 502 },
    );
  }

  // 1. Cambiar la contraseña: WordPress verifica la ACTUAL y fija la nueva en una sola
  //    llamada, con la sesión del usuario. No se usa `wp/v2/users/<id>`: `security.php`
  //    retira esa ruta a quien no puede listar usuarios, así que a un cliente le daba 404.
  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/change-password`, {
      method: "POST",
      cache: "no-store",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${session.token}`,
      },
      body: JSON.stringify({
        current_password: parsed.data.currentPassword,
        new_password: parsed.data.newPassword,
      }),
    });

    if (res.status === 403) {
      return NextResponse.json(
        { error: "La contraseña actual no es correcta." },
        { status: 403 },
      );
    }
    if (res.status === 429) {
      return NextResponse.json(
        { error: "Demasiados intentos. Inténtalo de nuevo más tarde." },
        { status: 429 },
      );
    }
    if (!res.ok) {
      logger.warn({ event: "auth.change_password.wp_error", status: res.status });
      return NextResponse.json(
        { error: "No se pudo cambiar la contraseña." },
        { status: 502 },
      );
    }
  } catch (error) {
    logger.error({
      event: "auth.change_password.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }

  // 2. La contraseña YA cambió. Cerrar las demás sesiones y conservar la actual. Un
  //    fallo aquí no se esconde: se informa al cliente para que no dé por cerradas
  //    unas sesiones que siguen abiertas.
  const otherSessionsRevoked = await revokeAllSessions(session.token);
  let sessionKept = true;
  if (otherSessionsRevoked) {
    sessionKept = await reissueCurrentSession(
      email,
      parsed.data.newPassword,
      session.token,
    );
  } else {
    logger.error(
      { event: "auth.change_password.sessions_not_revoked", userId: session.userId },
      "Contraseña cambiada, pero no se pudieron cerrar las demás sesiones",
    );
  }

  logger.info({
    event: "auth.change_password.success",
    userId: session.userId,
    otherSessionsRevoked,
    sessionKept,
  });
  return NextResponse.json(
    { ok: true, otherSessionsRevoked, sessionKept },
    { status: 200 },
  );
}
