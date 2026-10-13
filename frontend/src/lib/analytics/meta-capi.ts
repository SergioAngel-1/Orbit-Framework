import "server-only";
import crypto from "node:crypto";
import { getSiteConfig } from "@/lib/config";
import { checkoutReturnUrl } from "@/lib/checkout/return-url";
import { wcFetch } from "@/lib/woocommerce/client";
import { logger } from "@/lib/observability/logger";
import { routing } from "@/i18n/routing";
import type { WooOrder } from "@/types/woocommerce";
import { metaEvent, purchaseParams } from "./ecommerce";
import { getMetaCapiCredentials } from "./meta-capi-credentials";
import {
  ORDER_TRACKING_META,
  readOrderTracking,
  type OrderTrackingContext,
} from "./order-tracking";
import { countsAsPurchase } from "./purchase";
import { sanitizeTrackingIds } from "./services";

// ============================================================================
//  Conversions API de Meta: la compra enviada DESDE EL SERVIDOR cuando la
//  pasarela confirma el pago (webhook verificado), sin depender de que el
//  comprador vuelva a la página de gracias ni de su bloqueador de anuncios.
//
//  - Mismo evento que el píxel: `Purchase`, los mismos parámetros
//    (`metaEvent` sobre `purchaseParams`) y el mismo `event_id`
//    (`purchase_<pedido>`), que es lo que hace que Meta la cuente una vez.
//  - Solo con consentimiento: lo que el pedido guardó al crearse
//    (`order-tracking.ts`). Sin constancia de «Aceptar», no se envía. (Con el
//    módulo `consent-log`, la última decisión del registro manda además: una
//    retirada tras el checkout también lo impide.)
//  - Una vez por pedido: al enviarse se marca el pedido
//    (`_hwe_meta_capi_purchase_sent`). Si esa marca fallara, Meta deduplica
//    igual por `event_id` durante 48 h.
//  - Email y teléfono van cifrados con SHA-256, normalizados como pide Meta.
//    Nunca el nombre del producto.
//  - Token y código de prueba: Control Center → Integraciones
//    (`meta-capi-credentials.ts`). El id del píxel, el de la config pública.
// ============================================================================

/** Versión de la Graph API (v25.0: feb. 2026). Cada una vale ~2 años desde su publicación. */
export const META_GRAPH_VERSION = "v25.0";

const SEND_TIMEOUT_MS = 8_000;

export function sha256Hex(value: string): string {
  return crypto.createHash("sha256").update(value).digest("hex");
}

/** Email en minúsculas y sin espacios. Vacío si no parece un email. */
export function normalizeEmail(email: string | undefined | null): string {
  const clean = (email ?? "").trim().toLowerCase().replace(/\s+/g, "");
  return clean.includes("@") ? clean : "";
}

/**
 * Teléfono solo con dígitos y con indicativo (`34600123456`), como pide Meta.
 * Los del checkout ya llegan en E.164 (`+34…`); un número sin `+` ni `00` es
 * nacional y, si la tienda tiene un indicativo por defecto
 * (`auth.phone_default_dial_code`), se le antepone (sin el cero inicial). Sin
 * indicativo conocido se deja como está: mejor un número incompleto —Meta
 * simplemente no lo casará— que uno inventado. Vacío si no da para un número.
 */
export function normalizePhone(
  phone: string | undefined | null,
  defaultDialCode = "",
): string {
  const raw = (phone ?? "").trim();
  let digits = raw.replace(/\D/g, "");
  if (!raw.startsWith("+") && digits.startsWith("00")) {
    digits = digits.slice(2);
  } else if (!raw.startsWith("+")) {
    const dial = defaultDialCode.replace(/\D/g, "");
    if (dial !== "" && !digits.startsWith(dial)) {
      digits = `${dial}${digits.replace(/^0+/, "")}`;
    }
  }
  return digits.length >= 8 && digits.length <= 15 ? digits : "";
}

/** Hora del pago en segundos Unix (`date_paid_gmt` de wc/v3 viene sin zona). */
export function paidAtUnix(
  order: Pick<WooOrder, "date_paid_gmt">,
  now = Date.now(),
): number {
  const raw = order.date_paid_gmt;
  const ms = raw ? Date.parse(/[zZ]|[+-]\d\d:?\d\d$/.test(raw) ? raw : `${raw}Z`) : NaN;
  return Math.floor((Number.isFinite(ms) ? ms : now) / 1000);
}

export interface CapiEvent {
  event_name: string;
  event_time: number;
  event_id?: string;
  action_source: "website";
  event_source_url?: string;
  user_data: Record<string, string | string[]>;
  custom_data: Record<string, unknown>;
}

/**
 * El evento `Purchase` del pedido, o null si no da para uno (sin total). Mismos
 * parámetros que el píxel del navegador (`metaEvent` sobre `purchaseParams`).
 *
 * @param defaultDialCode indicativo por defecto de la tienda, para normalizar
 *   teléfonos nacionales (`auth.phone_default_dial_code`).
 */
export function buildPurchaseEvent(
  order: WooOrder,
  tracking: OrderTrackingContext,
  fallbackSourceUrl: string,
  defaultDialCode = "",
): CapiEvent | null {
  const base = purchaseParams(
    order.id,
    order.total,
    order.currency,
    order.line_items ?? [],
  );
  if (!base) return null;
  const meta = metaEvent("purchase", base);

  const userData: Record<string, string | string[]> = {};
  if (tracking.ip) userData.client_ip_address = tracking.ip;
  if (tracking.userAgent) userData.client_user_agent = tracking.userAgent;
  if (tracking.fbp) userData.fbp = tracking.fbp;
  if (tracking.fbc) userData.fbc = tracking.fbc;
  const email = normalizeEmail(order.billing?.email);
  if (email) userData.em = [sha256Hex(email)];
  const phone = normalizePhone(order.billing?.phone, defaultDialCode);
  if (phone) userData.ph = [sha256Hex(phone)];

  return {
    event_name: meta.name,
    event_time: paidAtUnix(order),
    ...(meta.eventId ? { event_id: meta.eventId } : {}),
    action_source: "website",
    event_source_url: tracking.sourceUrl ?? fallbackSourceUrl,
    user_data: userData,
    custom_data: meta.params,
  };
}

export type PurchaseSendResult =
  | "sent"
  | "already_sent"
  | "not_a_purchase"
  | "no_consent"
  | "not_configured"
  | "no_event"
  | "failed";

function alreadySent(order: WooOrder): boolean {
  return Boolean(
    order.meta_data?.some(
      (entry) => entry.key === ORDER_TRACKING_META.capiPurchaseSent && entry.value,
    ),
  );
}

/**
 * Envía la compra del pedido a Meta si procede. Nunca lanza: quien la llama
 * es el webhook de la pasarela, que no debe fallar por la medición.
 */
export async function sendPurchaseToMeta(order: WooOrder): Promise<PurchaseSendResult> {
  const log = { orderId: order.id };
  try {
    if (!countsAsPurchase(order)) return "not_a_purchase";
    if (alreadySent(order)) return "already_sent";
    const tracking = readOrderTracking(order.meta_data);
    if (!tracking.consent) {
      logger.info(
        { event: "analytics.meta_capi.no_consent", ...log },
        "Compra sin consentimiento: no se envía a Meta",
      );
      return "no_consent";
    }

    const [config, credentials] = await Promise.all([
      getSiteConfig(),
      getMetaCapiCredentials(),
    ]);
    const pixelId = sanitizeTrackingIds(config.integrations).meta_pixel_id;
    if (!pixelId || !credentials.accessToken) return "not_configured";

    // Pedidos creados antes de guardar el contexto: la «gracias» en el idioma
    // por defecto.
    const fallbackUrl = checkoutReturnUrl(
      config.brand.url,
      routing.defaultLocale,
      order.id,
    );
    const event = buildPurchaseEvent(
      order,
      tracking,
      fallbackUrl,
      config.auth.phone_default_dial_code,
    );
    if (!event) return "no_event";

    // El token va en el CUERPO, no en la URL: así no acaba en ningún log de
    // acceso ni en el mensaje de un error de red.
    const res = await fetch(
      `https://graph.facebook.com/${META_GRAPH_VERSION}/${pixelId}/events`,
      {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          data: [event],
          access_token: credentials.accessToken,
          ...(credentials.testEventCode
            ? { test_event_code: credentials.testEventCode }
            : {}),
        }),
        cache: "no-store",
        signal: AbortSignal.timeout(SEND_TIMEOUT_MS),
      },
    );
    const body = (await res.json().catch(() => null)) as {
      events_received?: number;
      fbtrace_id?: string;
      error?: { message?: string; code?: number };
    } | null;

    if (!res.ok) {
      logger.error(
        {
          event: "analytics.meta_capi.rejected",
          ...log,
          httpStatus: res.status,
          metaError: body?.error?.message ?? null,
          metaCode: body?.error?.code ?? null,
        },
        "Meta rechazó la compra enviada desde el servidor",
      );
      return "failed";
    }

    await wcFetch(`/orders/${order.id}`, {
      method: "PUT",
      body: {
        meta_data: [
          {
            key: ORDER_TRACKING_META.capiPurchaseSent,
            value: new Date().toISOString(),
          },
        ],
      },
    }).catch((error: unknown) => {
      // La compra ya salió: si no queda la marca, un reenvío lo deduplica Meta.
      logger.warn(
        {
          event: "analytics.meta_capi.mark_failed",
          ...log,
          err: error instanceof Error ? error.message : error,
        },
        "Compra enviada a Meta pero no se pudo marcar el pedido",
      );
    });

    logger.info(
      {
        event: "analytics.meta_capi.sent",
        ...log,
        eventId: event.event_id,
        eventsReceived: body?.events_received ?? null,
        fbtraceId: body?.fbtrace_id ?? null,
        test: Boolean(credentials.testEventCode),
      },
      "Compra enviada a Meta (Conversions API)",
    );
    return "sent";
  } catch (error) {
    logger.error(
      {
        event: "analytics.meta_capi.error",
        ...log,
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo enviar la compra a Meta",
    );
    return "failed";
  }
}
