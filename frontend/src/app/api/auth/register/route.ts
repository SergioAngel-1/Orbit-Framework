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
import { syncAuthenticatedCart } from "@/lib/woocommerce/cart-session";
import { claimGuestOrder } from "@/lib/checkout/guest-order-claim";
import { linkConsentReceipt } from "@/lib/analytics/consent-ledger";
import { logger } from "@/lib/observability/logger";
import type { RegisterResponse, LoginResponse } from "@/types/auth";
import { authEmailLocale, authEmailUrl } from "@/lib/auth/email-links";
import { getSiteConfig } from "@/lib/config";
import { attachPhoneToAccount, isPhoneAvailable } from "@/lib/auth/phone-account";
import { wpFetch } from "@/lib/wordpress/internal-fetch";

export const dynamic = "force-dynamic";

/**
 * Dispara el email de verificación tras el registro (best-effort). La política
 * es OPCIONAL: NO bloquea el uso de la cuenta; solo inicia el flujo para que el
 * usuario pueda verificar cuando quiera (UI en /verify-email + reenvío). Un
 * fallo de envío no rompe el registro.
 */
async function sendVerificationEmail(authToken: string, locale: string): Promise<void> {
  try {
    const res = await wpFetch(`/wp-json/hwe/v1/auth/send-verification`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${authToken}`,
      },
      body: JSON.stringify({
        verification_url: authEmailUrl("/verify-email", locale),
        locale,
      }),
    });
    // WordPress contesta 502 si wp_mail() falla (SMTP caído o mal configurado):
    // no rompe el registro, pero tampoco pasa en silencio.
    if (!res.ok) {
      logger.warn(
        { event: "auth.register.send_verification_failed", status: res.status },
        "WordPress no pudo enviar el email de verificación tras el registro",
      );
    }
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

  // 0) Teléfono (opcional): solo con `auth.phone_login_enabled` y si está libre.
  //    Se comprueba ANTES de crear la cuenta; tras crearla, WordPress lo vuelve
  //    a comprobar al guardarlo.
  const phone = parsed.data.phone || "";
  if (phone !== "") {
    const config = await getSiteConfig();
    if (!config.auth.phone_login_enabled) {
      return NextResponse.json(
        { error: "Datos inválidos.", details: { phone: ["No se admite teléfono."] } },
        { status: 422 },
      );
    }
    const available = await isPhoneAvailable(phone);
    if (available === null) {
      return NextResponse.json(
        { error: "No se pudo comprobar el teléfono. Inténtalo de nuevo." },
        { status: 503 },
      );
    }
    if (!available) {
      return NextResponse.json(
        {
          error: "Ese teléfono ya está en uso.",
          details: { phone: ["Ese teléfono ya está en uso."] },
        },
        { status: 409 },
      );
    }
  }

  // 1) Crear el usuario.
  try {
    await fetchGraphQL<RegisterResponse>(REGISTER_USER_MUTATION, {
      variables: {
        username: parsed.data.username,
        email: parsed.data.email,
        password: parsed.data.password,
      },
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

    // Teléfono de la cuenta: si no se pudo guardar (carrera con otro registro),
    // la cuenta ya existe y sigue sirviendo con el email; se registra.
    if (phone !== "") {
      await attachPhoneToAccount(user.databaseId, phone);
    }

    // Inicia el flujo de verificación de email (opcional, no bloqueante).
    if (authToken) {
      await sendVerificationEmail(authToken, authEmailLocale(parsed.data.locale));
    }

    // Ver `login`: carrito de la cuenta y pedido de invitado de este
    // navegador, antes de responder. Una cuenta nueva suele tener el carrito
    // vacío: lo que importa aquí es ligar el pedido que acaba de hacer.
    await syncAuthenticatedCart(authToken);
    await claimGuestOrder(user.databaseId);
    // Liga el comprobante de consentimiento de este dispositivo a la cuenta
    // (módulo `consent-log`; en `after()`, no retrasa la respuesta).
    await linkConsentReceipt(request, user.databaseId);

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
