import { describe, expect, it } from "vitest";
import { createTranslator } from "next-intl";
import es from "@/i18n/messages/es.json";
import { catalogFor, hasOwnCatalog } from "@/i18n/messages/catalogs";
import {
  consentMessageArgs,
  consentProviders,
  resolveTrackingServices,
  servicesFromNames,
  trackingServiceNames,
  type AnalyticsIntegrationsConfig,
} from "./services";

const EMPTY: AnalyticsIntegrationsConfig = {
  analytics_provider: "",
  analytics_id: "",
  gtm_id: "",
  meta_pixel_id: "",
  tiktok_pixel_id: "",
  hubspot_portal_id: "",
};

describe("consentProviders", () => {
  it("nombra solo los terceros configurados", () => {
    const services = resolveTrackingServices({ ...EMPTY, meta_pixel_id: "123" });
    expect(consentProviders(services)).toBe("Meta");
  });

  it("con GTM no nombra GA4: el contenedor lo carga por dentro", () => {
    const services = resolveTrackingServices({
      ...EMPTY,
      analytics_provider: "ga4",
      analytics_id: "G-ABC123",
      gtm_id: "GTM-XYZ",
      meta_pixel_id: "123",
    });
    expect(consentProviders(services)).toBe("Google Tag Manager, Meta");
  });

  it("nombra a HubSpot", () => {
    const services = resolveTrackingServices({
      ...EMPTY,
      hubspot_portal_id: "12345678",
    });
    expect(consentProviders(services)).toBe("HubSpot");
  });
});

describe("trackingServiceNames / servicesFromNames", () => {
  it("nombra los servicios activos en el orden del banner", () => {
    const services = resolveTrackingServices({
      ...EMPTY,
      hubspot_portal_id: "12345678",
      analytics_provider: "ga4",
      analytics_id: "G-ABC123",
      meta_pixel_id: "123",
    });
    expect(trackingServiceNames(services)).toEqual(["ga4", "meta", "hubspot"]);
  });

  it("de una lista de nombres rehace los mismos servicios (y el mismo texto)", () => {
    const services = resolveTrackingServices({
      ...EMPTY,
      gtm_id: "GTM-XYZ",
      tiktok_pixel_id: "ABC",
    });
    const rebuilt = servicesFromNames(["tiktok", "gtm", "desconocido"]);
    expect(rebuilt).toEqual(services);
    expect(consentMessageArgs(rebuilt)).toEqual(consentMessageArgs(services));
  });

  it("una lista vacía no pide nada", () => {
    expect(servicesFromNames([]).any).toBe(false);
  });
});

// El registro de consentimientos guarda el texto que rehace el SERVIDOR con
// `t.markup`; el banner lo pinta con `t.rich`. Los dos tienen que decir lo
// mismo, enlace a la política incluido (su texto visible).
describe("consent.message", () => {
  const services = resolveTrackingServices({ ...EMPTY, meta_pixel_id: "123" });

  it("en español nombra a Meta y enlaza la política", () => {
    const t = createTranslator({ locale: "es", messages: es, namespace: "consent" });
    expect(
      t.markup("message", { ...consentMessageArgs(services), link: (c) => c }),
    ).toBe(
      "Esta web usa cookies técnicas necesarias y, si aceptas, cookies publicitarias de Meta para mostrarte anuncios relevantes. Política de cookies",
    );
  });

  // Sin catálogo propio para `en` (instancia monolingüe) no hay traducción que comprobar.
  it.skipIf(!hasOwnCatalog("en"))("en inglés también", () => {
    const t = createTranslator({
      locale: "en",
      messages: catalogFor("en"),
      namespace: "consent",
    });
    expect(
      t.markup("message", { ...consentMessageArgs(services), link: (c) => c }),
    ).toBe(
      "This site uses necessary technical cookies and, if you accept, advertising cookies from Meta to show you relevant ads. Cookie policy",
    );
  });
});

describe("Plausible", () => {
  const plausible = {
    ...EMPTY,
    analytics_provider: "plausible",
    analytics_id: "tienda.example",
  };

  it("se activa con proveedor «plausible» y el dominio del sitio", () => {
    const services = resolveTrackingServices(plausible);

    expect(services.plausible).toBe(true);
    expect(services.ga4).toBe(false);
    expect(services.analytics).toBe(true);
    expect(services.any).toBe(true);
  });

  it("el dominio se normaliza a minúsculas y se valida: lo que no parece un dominio no carga", () => {
    expect(
      resolveTrackingServices({ ...plausible, analytics_id: " Tienda.Example " })
        .plausible,
    ).toBe(true);
    for (const bad of ["tienda", "tienda.example/'; alert(1)", "a b.com", ""]) {
      expect(
        resolveTrackingServices({ ...plausible, analytics_id: bad }).plausible,
      ).toBe(false);
    }
  });

  it("un id de GA4 no vale como dominio de Plausible, ni al revés", () => {
    expect(
      resolveTrackingServices({ ...plausible, analytics_id: "G-ABC123" }).plausible,
    ).toBe(false);
    expect(
      resolveTrackingServices({
        ...EMPTY,
        analytics_provider: "ga4",
        analytics_id: "tienda.example",
      }).ga4,
    ).toBe(false);
  });

  it("el banner lo nombra y el servicio tiene nombre estable", () => {
    const services = resolveTrackingServices(plausible);

    expect(consentProviders(services)).toBe("Plausible");
    expect(trackingServiceNames(services)).toEqual(["plausible"]);
    expect(servicesFromNames(["plausible"]).plausible).toBe(true);
  });

  it("no lo desactiva GTM: es independiente", () => {
    const services = resolveTrackingServices({ ...plausible, gtm_id: "GTM-ABC" });

    expect(services.plausible).toBe(true);
    expect(services.gtm).toBe(true);
  });
});
