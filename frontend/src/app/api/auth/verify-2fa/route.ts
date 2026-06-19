import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { jwtVerify } from "jose";
import { verify as verifyTotp } from "otplib";
import { twoFactorCodeSchema } from "@/lib/validation/auth";
import { guardMutation } from "@/lib/api/guard";
import {
  AUTH_COOKIE,
  REFRESH_COOKIE,
  REFRESH_TOKEN_MAX_AGE,
} from "@/lib/auth/constants";
import { sessionCookieOptions, refreshCookieOptions } from "@/lib/security/cookies";
import { getTokenMaxAgeSeconds } from "@/lib/auth/jwt";
import { logger } from "@/lib/observability/logger";

export const dynamic = "force-dynamic";

const WP_INTERNAL = process.env.WORDPRESS_INTERNAL_API_URL?.replace("/graphql", "") ?? "http://wordpress:80";
const EPHEMERAL_SECRET = new TextEncoder().encode(
  process.env.GRAPHQL_JWT_AUTH_SECRET_KEY || "fallback-dev-secret-change-in-prod",
);

interface EphemeralPayload {
  authToken: string;
  refreshToken: string;
  userId: string;
}

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "verify_2fa", limit: 5, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  let body: { ephemeralToken?: string; code?: string };
  try {
    body = await request.json() as { ephemeralToken?: string; code?: string };
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  if (!body.ephemeralToken || !body.code) {
    return NextResponse.json({ error: "Token y código requeridos." }, { status: 422 });
  }

  const parsed = twoFactorCodeSchema.safeParse({ code: body.code });
  if (!parsed.success) {
    return NextResponse.json(
      { error: "Código inválido.", details: parsed.error.flatten().fieldErrors },
      { status: 422 },
    );
  }

  // Verify ephemeral token
  let ephemeral: EphemeralPayload;
  try {
    const { payload } = await jwtVerify(body.ephemeralToken, EPHEMERAL_SECRET, {
      algorithms: ["HS256"],
    });
    ephemeral = payload as unknown as EphemeralPayload;
  } catch {
    logger.warn({ event: "2fa.verify_login.invalid_ephemeral" }, "Token efímero inválido");
    return NextResponse.json({ error: "Sesión expirada. Inicia sesión de nuevo." }, { status: 401 });
  }

  // Check if 2FA is enabled
  try {
    const statusRes = await fetch(
      `${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-status/${ephemeral.userId}`,
      { cache: "no-store" },
    );
    if (!statusRes.ok) {
      return NextResponse.json({ error: "Error de verificación." }, { status: 502 });
    }
    const statusData = await statusRes.json() as { enabled: boolean };
    if (!statusData.enabled) {
      return NextResponse.json({ error: "2FA no está habilitado para esta cuenta." }, { status: 400 });
    }

    // Get the secret to verify
    const secretRes = await fetch(`${WP_INTERNAL}/wp-json/hwe/v1/auth/2fa-secret`, {
      headers: {
        Authorization: `Bearer ${ephemeral.authToken}`,
      },
      cache: "no-store",
    });
    if (!secretRes.ok) {
      return NextResponse.json({ error: "Error al obtener configuración 2FA." }, { status: 502 });
    }
    const secretData = await secretRes.json() as { secret: string | null; enabled: boolean };
    if (!secretData.secret) {
      return NextResponse.json({ error: "2FA no está configurado." }, { status: 400 });
    }

    const totpResult = await verifyTotp({
      token: parsed.data.code,
      secret: secretData.secret,
    });

    if (!totpResult.valid) {
      logger.warn({ event: "2fa.verify_login.invalid_code" }, "Código 2FA inválido en login");
      return NextResponse.json({ error: "Código inválido." }, { status: 401 });
    }

    // Set session cookies
    const store = await cookies();
    store.set(
      AUTH_COOKIE,
      ephemeral.authToken,
      sessionCookieOptions(getTokenMaxAgeSeconds(ephemeral.authToken)),
    );
    store.set(
      REFRESH_COOKIE,
      ephemeral.refreshToken,
      refreshCookieOptions(REFRESH_TOKEN_MAX_AGE),
    );

    logger.info({ event: "2fa.verify_login.success", userId: ephemeral.userId });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({ event: "2fa.verify_login.error", err: error instanceof Error ? error.message : error });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }
}
