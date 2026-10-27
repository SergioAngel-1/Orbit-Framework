import { NextResponse } from "next/server";
import { BodyTooLarge, bodyTooLarge, readJsonBody } from "@/lib/api/body";
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
  expiredCookieOptions,
} from "@/lib/security/cookies";
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
import { getGeoPack } from "@/lib/geo";
import { isModuleEnabled } from "@/lib/config/modules";
import { identityPolicy } from "@/lib/customer/policy";
import { attachIdentity, isDocumentAvailable } from "@/lib/customer/identity-api";
import { checkDocument } from "@/lib/validation/identity";
import { attributeReferral } from "@/lib/loyalty/server";
import { normalizeReferralCode } from "@/lib/loyalty/referral-code";
import { REF_COOKIE } from "@/lib/loyalty/ref-cookie";

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
 * Retira la cookie `hwe_ref`: la atribución ya se intentó (o no hay a quién
 * atribuir) y no volvería a usarse en este navegador.
 */
async function forgetReferralCookie(): Promise<void> {
  const store = await cookies();
  store.set(REF_COOKIE, "", expiredCookieOptions("/"));
}

/**
 * Atribuye la cuenta recién creada al dueño del código de referido. Es
 * BEST-EFFORT: `attributeReferral` no lanza (normaliza los fallos), y aun así
 * aquí se blinda con `try` para que nada impida retirar la cookie ni romper el
 * alta. Nunca se registra el código ni el email: solo el id y el motivo.
 */
async function attributeRegistrationReferral(
  userId: number,
  code: string,
): Promise<void> {
  try {
    const result = await attributeReferral(userId, code);
    if (result.ok) {
      logger.info(
        { event: "auth.register.referral_attributed", userId },
        "Cuenta atribuida al código de referido",
      );
    } else if (result.reason === "unavailable") {
      logger.error(
        {
          event: "auth.register.referral_not_attributed",
          reason: result.reason,
          userId,
        },
        "No se pudo atribuir el referido: WordPress no respondió",
      );
    } else {
      logger.warn(
        {
          event: "auth.register.referral_not_attributed",
          reason: result.reason,
          userId,
        },
        "El referido no se atribuyó (código no válido o cuenta ya referida)",
      );
    }
  } catch (error) {
    logger.error(
      {
        event: "auth.register.referral_not_attributed",
        reason: "exception",
        userId,
        err: error instanceof Error ? error.name : "error",
      },
      "La atribución del referido lanzó; el registro continúa",
    );
  } finally {
    // La cookie ya cumplió su función, salga como salga la atribución.
    await forgetReferralCookie();
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
    body = await readJsonBody(request);
  } catch (error) {
    if (error instanceof BodyTooLarge) return bodyTooLarge();
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

  // 0a) Documento y términos (módulo `customer-identity`): con el módulo apagado no
  //     se piden. El documento es único entre cuentas: se comprueba ANTES de crear
  //     el usuario, igual que el teléfono, para no dejar una cuenta a medias.
  // El paquete de país solo se lee con el módulo encendido: un registro normal no
  // depende de él.
  const config = await getSiteConfig();

  // 0b) Código de referido (módulo `loyalty` + «Referidos»): el cuerpo gana a la
  //     cookie que dejó el proxy. Con el módulo o «Referidos» apagados no se lee
  //     ninguno de los dos y no se llama a WordPress; la cookie puede quedarse
  //     (el proxy la deja siempre porque no conoce la config).
  const referralsEnabled =
    isModuleEnabled(config, "loyalty") &&
    config.loyalty?.points_enabled === true &&
    config.loyalty?.referrals_enabled === true;
  let referralCode: string | null = null;
  if (referralsEnabled) {
    const fromBody = parsed.data.referral_code;
    if (typeof fromBody === "string" && fromBody.trim() !== "") {
      // Presente en el cuerpo (aunque el formato sea inválido): no se cae a la
      // cookie. Un código inválido simplemente no atribuye nada.
      referralCode = normalizeReferralCode(fromBody);
    } else {
      referralCode = normalizeReferralCode((await cookies()).get(REF_COOKIE)?.value);
    }
  }

  const policy = identityPolicy(
    config,
    isModuleEnabled(config, "customer-identity")
      ? await getGeoPack()
      : { documentTypes: [] },
  );
  const identity = policy.enabled ? parsed.data.identity : undefined;
  if (policy.enabled) {
    const details: Record<string, string[]> = {};
    if (parsed.data.terms_accepted !== true) {
      details.terms_accepted = [
        "Debes aceptar los términos y condiciones para continuar.",
      ];
    }
    if (policy.documentTypes.length > 0) {
      const invalid = identity
        ? checkDocument(policy.documentTypes, identity)
        : "Documento requerido.";
      if (invalid) details.identity = [invalid];
    }
    if (Object.keys(details).length > 0) {
      return NextResponse.json({ error: "Datos inválidos.", details }, { status: 422 });
    }
    if (identity) {
      const free = await isDocumentAvailable(
        identity.documentType,
        identity.documentNumber,
      );
      if (free === null) {
        logger.error(
          { event: "auth.register.document_check_failed" },
          "No se pudo comprobar la unicidad del documento",
        );
        return NextResponse.json(
          { error: "Servicio de autenticación temporalmente no disponible." },
          { status: 503 },
        );
      }
      if (!free) {
        const message = "Este documento ya está registrado.";
        return NextResponse.json(
          { error: message, details: { identity: [message] } },
          { status: 409 },
        );
      }
    }
  }

  // 0) Teléfono (opcional): solo con `auth.phone_login_enabled` y si está libre.
  //    Se comprueba ANTES de crear la cuenta; tras crearla, WordPress lo vuelve
  //    a comprobar al guardarlo.
  const phone = parsed.data.phone || "";
  if (phone !== "") {
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

  // 1) Crear el usuario. Se guarda el id real de WordPress que devuelve la
  //    mutación: la atribución del referido va con él y no depende del
  //    auto-login (si ese falla, la cuenta ya está atribuida).
  let newUserId: number | undefined;
  try {
    const registered = await fetchGraphQL<RegisterResponse>(REGISTER_USER_MUTATION, {
      variables: {
        username: parsed.data.username,
        email: parsed.data.email,
        password: parsed.data.password,
      },
      revalidate: 0,
    });
    newUserId = registered?.registerUser?.user?.databaseId;
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

  // 1b) Atribución del referido: tras crear la cuenta, con su id real y como
  //     best-effort. Un fallo no cambia la respuesta del registro; la cookie se
  //     retira igualmente.
  if (referralCode) {
    if (typeof newUserId === "number" && newUserId > 0) {
      await attributeRegistrationReferral(newUserId, referralCode);
    } else {
      logger.error(
        {
          event: "auth.register.referral_not_attributed",
          reason: "missing_user_id",
        },
        "No se pudo atribuir el referido: WordPress no devolvió el id de la cuenta",
      );
      await forgetReferralCookie();
    }
  }

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

    // Documento + aceptación de términos. Entre la comprobación de arriba y aquí otra
    // cuenta pudo quedarse el documento: es el único fallo que se registra como tal.
    // Si WordPress no responde, la cuenta sigue siendo válida: el documento se pedirá
    // en la cuenta o en el checkout, que lo aceptan mientras esté vacío.
    if (policy.enabled) {
      const saved = await attachIdentity(user.databaseId, {
        documentType: identity?.documentType,
        documentNumber: identity?.documentNumber,
        termsAccepted: true,
      });
      if (!saved.ok) {
        logger.error(
          {
            event: "auth.register.identity_incomplete",
            userId: user.databaseId,
            reason: saved.reason,
          },
          "Usuario creado pero no se pudo guardar el documento/aceptación",
        );
      }
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
