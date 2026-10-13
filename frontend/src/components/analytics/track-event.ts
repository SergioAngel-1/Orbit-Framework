import { readStoredConsent } from "@/lib/analytics/consent-storage";
import {
  metaEvent,
  tiktokParams,
  type EcommerceEventName,
  type EcommerceParams,
} from "@/lib/analytics/ecommerce";

/**
 * Envía un evento de e-commerce a los proveedores activos: GA4/GTM, Meta
 * Pixel (`fbq`) y TikTok Pixel (`ttq`). Respeta el consentimiento de forma
 * indirecta: cada global solo existe si `AnalyticsProvider` cargó su script
 * (consentimiento aceptado + ID configurado en HWE Control Center), así que
 * sin eso esta función es un no-op silencioso.
 *
 * Los parámetros los arma `lib/analytics/ecommerce.ts`, con la forma de GA4
 * (`currency`, `value`, `items`, `transaction_id`), y de ahí se traducen al
 * vocabulario de cada proveedor.
 *
 * Meta: ViewContent, AddToCart, InitiateCheckout y Purchase. Van con
 * `fbq('track', …)`: el descarte silencioso que obliga a usar `trackSingle` es
 * solo del PageView (ver `MetaPixelPageViews`). La compra lleva `eventID` =
 * `purchase_<pedido>`, el mismo que manda la Conversions API desde el
 * servidor, para que Meta la cuente una vez.
 */

const TIKTOK_EVENT_NAMES: Record<EcommerceEventName, string> = {
  view_item: "ViewContent",
  add_to_cart: "AddToCart",
  begin_checkout: "InitiateCheckout",
  purchase: "CompletePayment",
};

// ── Cola del hueco de hidratación ───────────────────────────────────────────
//
// El consentimiento se lee DESPUÉS de hidratar (`useSyncExternalStore` parte
// de «sin respuesta»), así que en una carga completa los scripts se montan un
// render más tarde que los efectos de la página. La vuelta de la pasarela a
// `/checkout/return` es justo eso: `PurchaseTracker` disparaba `purchase`
// sin `gtag` todavía, el evento se perdía y quedaba marcado como enviado.
//
// Mientras `AnalyticsProvider` no RESUELVA el consentimiento por primera vez,
// un evento se RETIENE solo si ya hay un «Aceptar» guardado —es ese hueco, no
// una decisión pendiente—; sin él se descarta. Resuelto, ya no se retiene
// nada: un «Aceptar» guardado que el provider no dio por bueno (no nombraba
// un servicio configurado hoy) vacía la cola, y lo que ocurra hasta el nuevo
// «Aceptar» tampoco se envía después.

const MAX_PENDING = 20;
let ready = false;
let resolved = false;
let pending: [EcommerceEventName, EcommerceParams][] = [];

function hasStoredAcceptance(): boolean {
  try {
    return readStoredConsent(localStorage)?.status === "accepted";
  } catch {
    return false;
  }
}

/**
 * ¿Los scripts corren con un consentimiento que los cubre? Es la decisión que
 * VALE (la resuelve `AnalyticsProvider`), no la guardada a secas: lo usa el
 * checkout para dejar constancia en el pedido (`lib/analytics/order-tracking.ts`)
 * y de ella depende que la compra salga también desde el servidor.
 */
export function hasTrackingConsent(): boolean {
  return ready;
}

/**
 * Lo llama `AnalyticsProvider` al resolverse el consentimiento: `true` cuando
 * los scripts consentidos ya corrieron (sus efectos van antes que los del
 * provider en el mismo commit), `false` sin consentimiento o sin servicios.
 */
export function setTrackingReady(value: boolean): void {
  ready = value;
  resolved = true;
  const queued = pending;
  pending = [];
  if (value) for (const [name, params] of queued) dispatch(name, params);
}

export function trackEvent(name: EcommerceEventName, params: EcommerceParams): void {
  if (typeof window === "undefined") return;
  if (ready) {
    dispatch(name, params);
  } else if (!resolved && hasStoredAcceptance()) {
    pending = [
      ...pending,
      [name, params] as [EcommerceEventName, EcommerceParams],
    ].slice(-MAX_PENDING);
  }
}

function dispatch(name: EcommerceEventName, params: EcommerceParams): void {
  send(name, params);
}

function send(name: EcommerceEventName, params: EcommerceParams): void {
  // GA4 directo (gtag.js) no procesa un `{ event }` empujado al dataLayer:
  // eso lo recoge GTM. Con gtag.js el evento tiene que ir por `gtag()`. Para
  // GTM, la convención de GA4 es el objeto `ecommerce`, vaciado antes para
  // que no se mezcle con el del evento anterior.
  if (typeof window.gtag === "function") {
    window.gtag("event", name, params);
  } else if (Array.isArray(window.dataLayer)) {
    window.dataLayer.push({ ecommerce: null });
    window.dataLayer.push({ event: name, ecommerce: params });
  }

  if (typeof window.fbq === "function") {
    const meta = metaEvent(name, params);
    if (meta.eventId) {
      window.fbq("track", meta.name, meta.params, { eventID: meta.eventId });
    } else {
      window.fbq("track", meta.name, meta.params);
    }
  }

  if (typeof window.ttq?.track === "function") {
    window.ttq.track(TIKTOK_EVENT_NAMES[name], tiktokParams(params));
  }

  // Plausible: eventos personalizados (los de e-commerce son «goals»), con los
  // ingresos de la compra. Sin nombres de producto.
  if (typeof window.plausible === "function") {
    const props: Record<string, unknown> = {
      items: params.items.reduce((n, item) => n + item.quantity, 0),
      ...(params.transaction_id ? { order_id: params.transaction_id } : {}),
    };
    window.plausible(
      name,
      name === "purchase"
        ? { props, revenue: { currency: params.currency, amount: params.value } }
        : { props },
    );
  }
}

declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
    fbq?: (...args: unknown[]) => void;
    ttq?: { track: (...args: unknown[]) => void };
    /** Cola del código de seguimiento de HubSpot (`HubSpotPageViews`). */
    _hsq?: unknown[][];
    plausible?: (
      event: string,
      options?: {
        props?: Record<string, unknown>;
        revenue?: { currency: string; amount: number };
      },
    ) => void;
  }
}
