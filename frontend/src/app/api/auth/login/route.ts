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
import { createPendingLogin } from "@/lib/auth/pending-login";
import { guardMutation } from "@/lib/api/guard";
import { syncAuthenticatedCart } from "@/lib/woocommerce/cart-session";
import { claimGuestOrder } from "@/lib/checkout/guest-order-claim";
import { linkConsentReceipt } from "@/lib/analytics/consent-ledger";
import { logger } from "@/lib/observability/logger";
import type { LoginResponse } from "@/types/auth";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { getSiteConfig } from "@/lib/config";
import { classifyLoginIdentifier } from "@/lib/auth/phone";
import { resolvePhoneToLogin } from "@/lib/auth/phone-account";

export const dynamic = "force-dynamic";

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

  // Con `auth.phone_login_enabled`, el «usuario» puede ser el teléfono de la
  // cuenta: se traduce a su email antes de pedir el token. Un teléfono que no es
  // de nadie responde como cualquier credencial inválida.
  let username = parsed.data.username;
  const config = await getSiteConfig();
  const identifier = classifyLoginIdentifier(parsed.data.username, {
    enabled: config.auth.phone_login_enabled,
    defaultDialCode: config.auth.phone_default_dial_code,
  });
  if (identifier.kind === "phone") {
    const resolved = await resolvePhoneToLogin(identifier.value);
    if (!resolved) {
      logger.info(
        { event: "auth.login.failed", reason: "phone_unresolved" },
        "Credenciales inválidas en login",
      );
      return NextResponse.json({ error: "Credenciales inválidas." }, { status: 401 });
    }
    username = resolved;
  }

  let data: LoginResponse;
  try {
    data = await fetchGraphQL<LoginResponse>(LOGIN_MUTATION, {
      variables: { username, password: parsed.data.password },
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
    const statusRes = await wpFetch(
      `/wp-json/hwe/v1/auth/2fa-status/${user.databaseId}`,
      {
        cache: "no-store",
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
    // Las credenciales reales se guardan en el servidor (Redis); el cliente solo
    // recibe un id opaco de un solo uso para reclamarlas tras verificar el
    // segundo factor. NUNCA viajan tokens reales al navegador antes del 2FA: un
    // JWT firmado no oculta su contenido, y bastaba decodificarlo en base64 para
    // obtener la sesión completa saltándose el TOTP.
    const ephemeralToken = await createPendingLogin({
      authToken,
      refreshToken,
      userId: String(user.databaseId),
    });
    if (!ephemeralToken) {
      // Fail-closed: sin dónde guardarlas con seguridad no se emite el login.
      logger.error(
        { event: "auth.login.pending_login_unavailable" },
        "No se pudo iniciar el login pendiente de 2FA (Redis no disponible)",
      );
      return NextResponse.json(
        { error: "Servicio de autenticación temporalmente no disponible." },
        { status: 503 },
      );
    }

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

  // El carrito de la cuenta prevalece sobre el del navegador, y el pedido de
  // invitado de este dispositivo pasa a la cuenta (si el correo es el mismo).
  // Van ANTES de responder: la cookie del pedido solo se retira en la respuesta
  // y «Mis pedidos» ya tiene que mostrarlo. Ninguna lanza.
  await syncAuthenticatedCart(authToken);
  await claimGuestOrder(user.databaseId);
  // Liga el comprobante de consentimiento de este dispositivo a la cuenta
  // (módulo `consent-log`; en `after()`, no retrasa la respuesta).
  await linkConsentReceipt(request, user.databaseId);

  logger.info({ event: "auth.login.success", userId: user.id }, "Login exitoso");
  return NextResponse.json({ user }, { status: 200 });
}
