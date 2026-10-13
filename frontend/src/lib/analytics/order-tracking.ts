// ============================================================================
//  Contexto de medición que se guarda EN EL PEDIDO al crearlo, para que la
//  compra se pueda enviar desde el servidor (Conversions API de Meta) cuando
//  la pasarela la confirme — minutos u horas después, sin el navegador.
//
//  Lo que se guarda depende del consentimiento del banner de cookies: sin
//  «Aceptar» solo queda constancia de que NO se aceptó, y el envío desde el
//  servidor no ocurre. Con «Aceptar»: las cookies del píxel (`_fbp`, `_fbc`),
//  la IP y el navegador del comprador, y la URL de gracias del idioma en que
//  compró (`event_source_url`).
//
//  Order meta con `_` delante: WooCommerce no la enseña como campo
//  personalizado ni la devuelve la Store API. Puro: lo usan la ruta de
//  checkout (escritura) y el envío de la compra (lectura).
// ============================================================================

import { isConsentReceipt } from "./consent-record";

export const ORDER_TRACKING_META = {
  consent: "_hwe_tracking_consent",
  fbp: "_hwe_fbp",
  fbc: "_hwe_fbc",
  ip: "_hwe_client_ip",
  userAgent: "_hwe_client_user_agent",
  sourceUrl: "_hwe_event_source_url",
  /**
   * Comprobante del registro de consentimientos del dispositivo que compró
   * (cookie `hwe_consent`, módulo `consent-log`): el envío lo usa para
   * comprobar que la persona no RETIRÓ el consentimiento entre el checkout y la
   * confirmación del pago.
   */
  consentReceipt: "_hwe_consent_receipt",
  /** Marca de «compra ya enviada por la Conversions API» (la pone el envío). */
  capiPurchaseSent: "_hwe_meta_capi_purchase_sent",
} as const;

export interface OrderTrackingContext {
  /** El comprador aceptó las cookies de medición al comprar. */
  consent: boolean;
  fbp?: string;
  fbc?: string;
  ip?: string;
  userAgent?: string;
  sourceUrl?: string;
  consentReceipt?: string;
}

type MetaEntry = { key: string; value: string };

const MAX_LENGTH = 512;

/**
 * `_fbp` = `fb.1.<ms>.<aleatorio>` y `_fbc` = `fb.1.<ms>.<fbclid>`. Son
 * cookies que escribe un script de terceros en el navegador: se guardan solo
 * si tienen esa forma, para no meter en el pedido lo que venga en su lugar.
 */
export function isValidFbCookie(value: string | undefined): value is string {
  return (
    typeof value === "string" &&
    value.length <= MAX_LENGTH &&
    /^fb\.\d\.\d+\.[^\s;,]+$/.test(value)
  );
}

function clean(value: string | undefined): string | undefined {
  const trimmed = value?.trim();
  return trimmed ? trimmed.slice(0, MAX_LENGTH) : undefined;
}

export function orderTrackingMetaData(ctx: OrderTrackingContext): MetaEntry[] {
  if (!ctx.consent) return [{ key: ORDER_TRACKING_META.consent, value: "declined" }];

  const entries: MetaEntry[] = [
    { key: ORDER_TRACKING_META.consent, value: "accepted" },
  ];
  const add = (key: string, value: string | undefined) => {
    if (value) entries.push({ key, value });
  };
  add(ORDER_TRACKING_META.fbp, isValidFbCookie(ctx.fbp) ? ctx.fbp : undefined);
  add(ORDER_TRACKING_META.fbc, isValidFbCookie(ctx.fbc) ? ctx.fbc : undefined);
  const ip = clean(ctx.ip);
  add(ORDER_TRACKING_META.ip, ip === "unknown" ? undefined : ip);
  add(ORDER_TRACKING_META.userAgent, clean(ctx.userAgent));
  add(ORDER_TRACKING_META.sourceUrl, clean(ctx.sourceUrl));
  add(
    ORDER_TRACKING_META.consentReceipt,
    isConsentReceipt(ctx.consentReceipt) ? ctx.consentReceipt : undefined,
  );
  return entries;
}

/** Lee el contexto del `meta_data` de wc/v3. Sin constancia = sin consentimiento. */
export function readOrderTracking(
  metaData: ReadonlyArray<{ key: string; value: unknown }> | undefined,
): OrderTrackingContext {
  const get = (key: string): string | undefined => {
    const value = metaData?.find((entry) => entry.key === key)?.value;
    return typeof value === "string" && value !== "" ? value : undefined;
  };
  return {
    consent: get(ORDER_TRACKING_META.consent) === "accepted",
    fbp: get(ORDER_TRACKING_META.fbp),
    fbc: get(ORDER_TRACKING_META.fbc),
    ip: get(ORDER_TRACKING_META.ip),
    userAgent: get(ORDER_TRACKING_META.userAgent),
    sourceUrl: get(ORDER_TRACKING_META.sourceUrl),
    consentReceipt: get(ORDER_TRACKING_META.consentReceipt),
  };
}
