import "server-only";
import crypto from "node:crypto";
import { cookies } from "next/headers";
import { after } from "next/server";
import { getTranslations } from "next-intl/server";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { getClientIp } from "@/lib/http/request-ip";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logger } from "@/lib/observability/logger";
import { consentReceiptCookieOptions } from "@/lib/security/cookies";
import { buildConsentLedgerEntry } from "./consent-entry";
import {
  CONSENT_RECEIPT_COOKIE,
  CONSENT_RECEIPT_MAX_AGE,
  clampUserAgent,
  isConsentReceipt,
  truncateIp,
  type ConsentRecordRequest,
} from "./consent-record";
import {
  consentMessageArgs,
  resolveTrackingServices,
  servicesFromNames,
  trackingServiceNames,
} from "./services";

// ============================================================================
//  Registro de consentimientos de cookies: la PRUEBA de cada decisión.
//
//  Navegador ─POST /api/consent─► BFF ─(X-HWE-Internal-Secret)─► WordPress
//   {decision, action, ruta,        añade usuario (sesión), IP     tabla
//    variante, idioma,              truncada, user-agent, texto     wp_hwe_consent_log
//    servicios que nombró}          enseñado (rehecho con esos      (+ hora del servidor
//                                   servicios), comprobante          y versión de la política)
//
//  - Anónimo: lo liga el COMPROBANTE, un UUID en la cookie httpOnly
//    `hwe_consent` que se crea en la primera decisión.
//  - Con sesión: la fila lleva además el `user_id`. Si la persona decidió
//    como anónima y luego inicia sesión, `linkConsentReceipt` añade una fila
//    `linked` (el registro solo crece: nunca se reescribe una fila). En un
//    dispositivo compartido, un comprobante ya ligado a OTRA cuenta no se
//    vuelve a vincular (lo decide WordPress).
//  - No se sincroniza entre dispositivos: el consentimiento de cookies es de
//    cada navegador, y cada uno pregunta y registra el suyo.
//
//  Es independiente de la medición anónima (`consent-stats.ts`, Redis): esa
//  no identifica a nadie y puede perder datos (Redis expulsa claves); esta
//  vive en MySQL, entra en las copias de seguridad y se conserva el plazo de
//  Control Center → Integraciones (5 años por defecto). Es del módulo
//  `consent-log`: sin él, el consentimiento sigue siendo solo local.
// ============================================================================

/** El registro no debe colgar un login ni la respuesta al banner. */
const TIMEOUT_MS = 5000;

async function postInternal(path: string, body: unknown): Promise<Response | null> {
  try {
    return await wpFetch(path, {
      method: "POST",
      cache: "no-store",
      signal: AbortSignal.timeout(TIMEOUT_MS),
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
  } catch {
    return null;
  }
}

function requestMeta(request: Request) {
  return {
    ip: truncateIp(getClientIp(request)),
    userAgent: clampUserAgent(request.headers.get("user-agent")),
  };
}

export type RecordConsentResult =
  | { ok: true; receipt: string }
  | { ok: false; reason: "nothing_to_consent" | "unavailable" };

/**
 * Registra una decisión. El comprobante solo se DEVUELVE (y el navegador lo
 * enseña) si WordPress la guardó; la cookie, en cambio, se fija siempre, para
 * que un reintento reuse el mismo (ver abajo).
 */
export async function recordConsentDecision(
  request: Request,
  parsed: ConsentRecordRequest,
): Promise<RecordConsentResult> {
  const [store, session, config] = await Promise.all([
    cookies(),
    getSession(),
    getSiteConfig(),
  ]);
  // Lo que NOMBRÓ el banner, que es lo que se consintió. Puede no ser lo
  // configurado hoy (una página servida antes de cambiar Integraciones, o una
  // decisión que se reenvía de la cola horas después): el texto de la prueba
  // tiene que ser el que se vio. Solo lo encolado antes de existir el campo
  // cae a la configuración actual.
  const configured = resolveTrackingServices(config.integrations);
  const services = parsed.services ? servicesFromNames(parsed.services) : configured;
  // Sin servicios no hay banner: nada que consentir ni que probar.
  if (!services.any) return { ok: false, reason: "nothing_to_consent" };
  const shownNames = trackingServiceNames(services);
  const configuredNames = trackingServiceNames(configured);
  if (shownNames.join(",") !== configuredNames.join(",")) {
    logger.info(
      {
        event: "consent_ledger.services_differ",
        shown: shownNames,
        configured: configuredNames,
      },
      "El banner de esta decisión nombraba otros servicios que los configurados ahora",
    );
  }

  const existing = store.get(CONSENT_RECEIPT_COOKIE)?.value;
  const receipt = isConsentReceipt(existing) ? existing : crypto.randomUUID();

  // El texto EXACTO que pintó el banner: mismo catálogo del mismo build, mismo
  // idioma y mismos argumentos (que salen de los mismos servicios). `markup`
  // y no `t()`: el mensaje lleva el enlace a la política (`<link>`), y aquí se
  // guarda su texto visible, sin la etiqueta.
  const t = await getTranslations({ locale: parsed.locale, namespace: "consent" });
  const text = t.markup("message", {
    ...consentMessageArgs(services),
    link: (chunks) => chunks,
  });

  const { ip, userAgent } = requestMeta(request);
  const entry = buildConsentLedgerEntry({
    receipt,
    userId: session?.userId ?? null,
    request: parsed,
    services,
    text,
    ip,
    userAgent,
  });

  // La cookie va ANTES de llamar a WordPress, también si luego falla. Un
  // fallo aquí puede ser un corte por tiempo con la fila YA insertada: sin la
  // cookie, el reintento de la cola estrenaba otro UUID, WordPress no lo
  // reconocía como duplicado (lo busca por comprobante) y la misma decisión
  // quedaba dos veces, con dos comprobantes. Con ella, el reintento reusa el
  // comprobante y WordPress lo descarta. Una cookie sin fila no prueba nada
  // ni hace daño: el navegador no enseña un comprobante hasta que el
  // servidor lo confirma, y vincularla a una cuenta sin filas no hace nada.
  store.set(
    CONSENT_RECEIPT_COOKIE,
    receipt,
    consentReceiptCookieOptions(CONSENT_RECEIPT_MAX_AGE),
  );

  const res = await postInternal("/wp-json/hwe/v1/consent/record", entry);
  if (!res?.ok) {
    logger.error(
      { event: "consent_ledger.record_failed", httpStatus: res?.status ?? null },
      "No se pudo registrar el consentimiento en WordPress",
    );
    return { ok: false, reason: "unavailable" };
  }
  return { ok: true, receipt };
}

export type LatestConsent =
  | { status: "found"; decision: "accepted" | "declined" }
  | { status: "none" }
  | { status: "unavailable" };

/**
 * La decisión vigente de un comprobante en el registro (la de su última
 * fila). La usa el envío de la compra a la Conversions API: entre el checkout
 * y la confirmación del pago la persona pudo retirar el consentimiento en ese
 * dispositivo. `unavailable` si WordPress no responde: quien llama decide.
 */
export async function latestConsentDecision(receipt: string): Promise<LatestConsent> {
  if (!isConsentReceipt(receipt)) return { status: "none" };
  try {
    const res = await wpFetch(
      `/wp-json/hwe/v1/consent/latest?receipt=${encodeURIComponent(receipt)}`,
      { cache: "no-store", signal: AbortSignal.timeout(TIMEOUT_MS) },
    );
    if (!res.ok) return { status: "unavailable" };
    const body = (await res.json()) as { decision?: unknown };
    return body.decision === "accepted" || body.decision === "declined"
      ? { status: "found", decision: body.decision }
      : { status: "none" };
  } catch {
    return { status: "unavailable" };
  }
}

/**
 * Tras abrir sesión (login, 2FA, registro): liga el comprobante de este
 * dispositivo a la cuenta. Nunca lanza ni bloquea el login: el peor caso es
 * que las decisiones anónimas previas no aparezcan en el perfil.
 *
 * La llamada a WordPress va en `after()`, DESPUÉS de responder: es un
 * arranque completo de WP (~130 ms medidos en local, más en producción) que
 * el login no necesita esperar. Lo que depende de la petición (cookie, IP,
 * user-agent) se lee antes, mientras la petición sigue viva.
 */
export async function linkConsentReceipt(
  request: Request,
  userId: string | number,
): Promise<void> {
  try {
    const id = Number(userId);
    if (!Number.isInteger(id) || id <= 0) return;
    // Sin el módulo `consent-log` no hay registro al que vincular.
    if (!isModuleEnabled(await getSiteConfig(), "consent-log")) return;
    const receipt = (await cookies()).get(CONSENT_RECEIPT_COOKIE)?.value;
    if (!isConsentReceipt(receipt)) return;

    const { ip, userAgent } = requestMeta(request);
    after(async () => {
      const res = await postInternal("/wp-json/hwe/v1/consent/link", {
        receipt,
        user_id: id,
        ip,
        user_agent: userAgent,
      });
      if (!res?.ok) {
        logger.warn(
          { event: "consent_ledger.link_failed", httpStatus: res?.status ?? null },
          "No se pudo vincular el comprobante de consentimiento a la cuenta",
        );
      }
    });
  } catch {
    logger.warn(
      { event: "consent_ledger.link_failed" },
      "Vinculación de consentimiento fallida",
    );
  }
}
