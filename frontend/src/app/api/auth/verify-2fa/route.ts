import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { verify as verifyTotp } from "otplib";
import { twoFactorCodeSchema } from "@/lib/validation/auth";
import { guardMutation } from "@/lib/api/guard";
import { syncAuthenticatedCart } from "@/lib/woocommerce/cart-session";
import { claimGuestOrder } from "@/lib/checkout/guest-order-claim";
import { linkConsentReceipt } from "@/lib/analytics/consent-ledger";
import {
  AUTH_COOKIE,
  REFRESH_COOKIE,
  REFRESH_TOKEN_MAX_AGE,
} from "@/lib/auth/constants";
import { sessionCookieOptions, refreshCookieOptions } from "@/lib/security/cookies";
import { getTokenMaxAgeSeconds } from "@/lib/auth/jwt";
import {
  getPendingLogin,
  consumePendingLogin,
  recordFailedAttempt,
} from "@/lib/auth/pending-login";
import { logger } from "@/lib/observability/logger";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

export const dynamic = "force-dynamic";

const SESSION_EXPIRED = { error: "Sesión expirada. Inicia sesión de nuevo." };

/**
 * Responde a un código 2FA incorrecto y lo cuenta contra el login pendiente: al
 * agotarse el cupo el login queda invalidado (hay que volver a iniciar sesión).
 */
async function rejectBadCode(
  pendingId: string,
  message: string,
): Promise<NextResponse> {
  const locked = await recordFailedAttempt(pendingId);
  if (locked) {
    logger.warn(
      { event: "2fa.verify_login.locked" },
      "Intentos 2FA agotados en el login",
    );
    return NextResponse.json(
      { error: "Demasiados intentos. Inicia sesión de nuevo." },
      { status: 429 },
    );
  }
  return NextResponse.json({ error: message }, { status: 401 });
}

export async function POST(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "verify_2fa", limit: 5, windowSeconds: 60, strict: true },
  });
  if (blocked) return blocked;

  let body: { ephemeralToken?: string; code?: string; recoveryCode?: string };
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const usingRecovery = !!body.recoveryCode;

  if (!body.ephemeralToken || (!body.code && !body.recoveryCode)) {
    return NextResponse.json({ error: "Token y código requeridos." }, { status: 422 });
  }

  // Solo validamos el formato TOTP cuando no se usa un código de recuperación.
  let totpCode: string | null = null;
  if (!usingRecovery) {
    const parsed = twoFactorCodeSchema.safeParse({ code: body.code });
    if (!parsed.success) {
      return NextResponse.json(
        { error: "Código inválido.", details: parsed.error.flatten().fieldErrors },
        { status: 422 },
      );
    }
    totpCode = parsed.data.code;
  }

  // Recupera el login pendiente: las credenciales reales nunca viajaron al
  // cliente, solo este id opaco. NO se consume aquí: un código mal tecleado se
  // puede reintentar con el mismo id hasta que expire o se agote el cupo de
  // intentos (`recordFailedAttempt`). Se reclama al final, de forma atómica.
  const pendingId = body.ephemeralToken;
  const ephemeral = await getPendingLogin(pendingId);
  if (!ephemeral) {
    logger.warn(
      { event: "2fa.verify_login.invalid_ephemeral" },
      "Id de login pendiente inválido, caducado o ya usado",
    );
    return NextResponse.json(SESSION_EXPIRED, { status: 401 });
  }

  // Check if 2FA is enabled
  try {
    const statusRes = await wpFetch(
      `/wp-json/hwe/v1/auth/2fa-status/${ephemeral.userId}`,
      {
        cache: "no-store",
      },
    );
    if (!statusRes.ok) {
      return NextResponse.json({ error: "Error de verificación." }, { status: 502 });
    }
    const statusData = (await statusRes.json()) as { enabled: boolean };
    if (!statusData.enabled) {
      return NextResponse.json(
        { error: "2FA no está habilitado para esta cuenta." },
        { status: 400 },
      );
    }

    if (usingRecovery) {
      // Vía código de recuperación: WP valida y CONSUME el código (un solo uso).
      const recRes = await wpFetch(`/wp-json/hwe/v1/auth/2fa-recovery/verify`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${ephemeral.authToken}`,
        },
        body: JSON.stringify({ code: body.recoveryCode }),
        cache: "no-store",
      });
      if (!recRes.ok) {
        logger.warn(
          { event: "2fa.verify_login.invalid_recovery", userId: ephemeral.userId },
          "Código de recuperación inválido",
        );
        return rejectBadCode(pendingId, "Código de recuperación inválido.");
      }
    } else {
      // Vía TOTP: obtener el secreto (descifrado por WP) y verificar.
      const secretRes = await wpFetch(`/wp-json/hwe/v1/auth/2fa-secret`, {
        headers: {
          Authorization: `Bearer ${ephemeral.authToken}`,
        },
        cache: "no-store",
      });
      if (!secretRes.ok) {
        return NextResponse.json(
          { error: "Error al obtener configuración 2FA." },
          { status: 502 },
        );
      }
      const secretData = (await secretRes.json()) as {
        secret: string | null;
        enabled: boolean;
      };
      if (!secretData.secret) {
        return NextResponse.json(
          { error: "2FA no está configurado." },
          { status: 400 },
        );
      }

      const totpResult = await verifyTotp({
        token: totpCode!,
        secret: secretData.secret,
      });

      if (!totpResult.valid) {
        logger.warn(
          { event: "2fa.verify_login.invalid_code" },
          "Código 2FA inválido en login",
        );
        return rejectBadCode(pendingId, "Código inválido.");
      }
    }

    // Segundo factor verificado: se RECLAMA el login pendiente (lectura y borrado
    // atómicos). Si otra petición simultánea ya lo reclamó, esta no abre sesión:
    // el id es de un solo uso.
    const session = await consumePendingLogin(pendingId);
    if (!session) {
      logger.warn(
        { event: "2fa.verify_login.already_claimed", userId: ephemeral.userId },
        "El login pendiente ya se había reclamado",
      );
      return NextResponse.json(SESSION_EXPIRED, { status: 401 });
    }

    // Set session cookies
    const store = await cookies();
    store.set(
      AUTH_COOKIE,
      session.authToken,
      sessionCookieOptions(getTokenMaxAgeSeconds(session.authToken)),
    );
    store.set(
      REFRESH_COOKIE,
      session.refreshToken,
      refreshCookieOptions(REFRESH_TOKEN_MAX_AGE),
    );

    // Ver `login`: carrito de la cuenta y pedido de invitado, antes de responder.
    await syncAuthenticatedCart(session.authToken);
    await claimGuestOrder(ephemeral.userId);
    // Liga el comprobante de consentimiento de este dispositivo a la cuenta
    // (módulo `consent-log`; en `after()`, no retrasa la respuesta).
    await linkConsentReceipt(request, ephemeral.userId);

    logger.info({ event: "2fa.verify_login.success", userId: ephemeral.userId });
    return NextResponse.json({ ok: true }, { status: 200 });
  } catch (error) {
    logger.error({
      event: "2fa.verify_login.error",
      err: error instanceof Error ? error.message : error,
    });
    return NextResponse.json({ error: "Error de conexión." }, { status: 502 });
  }
}
