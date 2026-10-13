// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { EcommerceParams } from "@/lib/analytics/ecommerce";
import { CONSENT_GENERATION } from "@/lib/analytics/consent-storage";

// La cola es estado de MÓDULO: cada test importa una copia limpia.
async function load() {
  vi.resetModules();
  return import("./track-event");
}

const ITEM = { item_id: "7", item_name: "Camiseta", price: 45, quantity: 2 };

const PARAMS: EcommerceParams = {
  transaction_id: "9",
  currency: "EUR",
  value: 90,
  items: [ITEM],
};

// El `localStorage` global de Node enmascara al de jsdom: almacén propio.
let store: Record<string, string> = {};
const memoryStorage = {
  getItem: (k: string) => store[k] ?? null,
  setItem: (k: string, v: string) => {
    store[k] = v;
  },
  removeItem: (k: string) => {
    delete store[k];
  },
};

function storeConsent(status: "accepted" | "declined") {
  store["cookie-consent"] = JSON.stringify({
    v: CONSENT_GENERATION,
    status,
    services: null,
    receipt: null,
    pending: [],
  });
}

beforeEach(() => {
  store = {};
  vi.stubGlobal("localStorage", memoryStorage);
});

afterEach(() => {
  vi.unstubAllGlobals();
  delete window.gtag;
  delete window.dataLayer;
  delete window.fbq;
  delete window.ttq;
  delete window.plausible;
});

describe("trackEvent: consentimiento y cola", () => {
  it("con un «Aceptar» guardado retiene el evento hasta que los scripts corren", async () => {
    storeConsent("accepted");
    const { trackEvent, setTrackingReady } = await load();
    trackEvent("purchase", PARAMS); // hidratación: aún sin gtag

    const gtag = vi.fn();
    window.gtag = gtag;
    setTrackingReady(true);

    expect(gtag).toHaveBeenCalledWith("event", "purchase", PARAMS);
  });

  it("sin consentimiento guardado lo descarta: no se envía al aceptar después", async () => {
    const { trackEvent, setTrackingReady } = await load();
    trackEvent("add_to_cart", PARAMS);

    const gtag = vi.fn();
    window.gtag = gtag;
    setTrackingReady(true);

    expect(gtag).not.toHaveBeenCalled();
  });

  it("con consentimiento retirado, la cola se vacía sin enviar", async () => {
    storeConsent("accepted");
    const { trackEvent, setTrackingReady } = await load();
    trackEvent("purchase", PARAMS);
    setTrackingReady(false);

    const gtag = vi.fn();
    window.gtag = gtag;
    setTrackingReady(true);

    expect(gtag).not.toHaveBeenCalled();
  });

  it("resuelto sin consentimiento válido, un «Aceptar» guardado ya no retiene nada", async () => {
    storeConsent("accepted");
    const { trackEvent, setTrackingReady, hasTrackingConsent } = await load();
    setTrackingReady(false);
    trackEvent("add_to_cart", PARAMS);
    expect(hasTrackingConsent()).toBe(false);

    const gtag = vi.fn();
    window.gtag = gtag;
    setTrackingReady(true);

    expect(gtag).not.toHaveBeenCalled();
    expect(hasTrackingConsent()).toBe(true);
  });

  it("la cola tiene tope: solo se conservan los últimos eventos", async () => {
    storeConsent("accepted");
    const { trackEvent, setTrackingReady } = await load();
    for (let i = 0; i < 30; i += 1) trackEvent("add_to_cart", { ...PARAMS, value: i });

    const gtag = vi.fn();
    window.gtag = gtag;
    setTrackingReady(true);

    expect(gtag).toHaveBeenCalledTimes(20);
    expect(gtag.mock.calls[0]![2].value).toBe(10);
  });
});

describe("trackEvent: proveedores", () => {
  it("con gtag.js el evento va por `gtag()` con los parámetros de GA4", async () => {
    const { trackEvent, setTrackingReady } = await load();
    const gtag = vi.fn();
    window.gtag = gtag;
    setTrackingReady(true);

    trackEvent("view_item", PARAMS);

    expect(gtag).toHaveBeenCalledWith("event", "view_item", PARAMS);
  });

  it("con GTM (sin gtag) empuja el objeto `ecommerce`, vaciando el anterior", async () => {
    const { trackEvent, setTrackingReady } = await load();
    window.dataLayer = [];
    setTrackingReady(true);

    trackEvent("begin_checkout", PARAMS);

    expect(window.dataLayer).toEqual([
      { ecommerce: null },
      { event: "begin_checkout", ecommerce: PARAMS },
    ]);
  });

  it("manda a Meta la compra con el eventID de deduplicación y sin el nombre del producto", async () => {
    const { trackEvent, setTrackingReady } = await load();
    const fbq = vi.fn();
    window.fbq = fbq;
    setTrackingReady(true);

    trackEvent("purchase", PARAMS);

    expect(fbq).toHaveBeenCalledWith(
      "track",
      "Purchase",
      expect.objectContaining({ order_id: "9", content_ids: ["7"], value: 90 }),
      { eventID: "purchase_9" },
    );
    expect(JSON.stringify(fbq.mock.calls)).not.toContain("Camiseta");
  });

  it("los eventos sin eventID (ViewContent…) no llevan el cuarto argumento", async () => {
    const { trackEvent, setTrackingReady } = await load();
    const fbq = vi.fn();
    window.fbq = fbq;
    setTrackingReady(true);

    trackEvent("view_item", PARAMS);

    expect(fbq).toHaveBeenCalledWith("track", "ViewContent", expect.any(Object));
  });

  it("manda a TikTok el evento con su vocabulario", async () => {
    const { trackEvent, setTrackingReady } = await load();
    const track = vi.fn();
    window.ttq = { track };
    setTrackingReady(true);

    trackEvent("purchase", PARAMS);

    expect(track).toHaveBeenCalledWith(
      "CompletePayment",
      expect.objectContaining({ value: 90, currency: "EUR", order_id: "9" }),
    );
    expect(JSON.stringify(track.mock.calls)).not.toContain("Camiseta");
  });

  it("manda a Plausible el objetivo, con los ingresos de la compra", async () => {
    const { trackEvent, setTrackingReady } = await load();
    const plausible = vi.fn();
    window.plausible = plausible;
    setTrackingReady(true);

    trackEvent("purchase", PARAMS);

    expect(plausible).toHaveBeenCalledWith("purchase", {
      props: { items: 2, order_id: "9" },
      revenue: { currency: "EUR", amount: 90 },
    });
    expect(JSON.stringify(plausible.mock.calls)).not.toContain("Camiseta");
  });

  it("sin ningún script cargado es un no-op silencioso", async () => {
    const { trackEvent, setTrackingReady } = await load();
    setTrackingReady(true);

    expect(() => trackEvent("purchase", PARAMS)).not.toThrow();
  });
});
