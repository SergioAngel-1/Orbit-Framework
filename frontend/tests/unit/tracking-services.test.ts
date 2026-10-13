import { describe, expect, it } from "vitest";
import {
  consentKind,
  resolveTrackingServices,
  sanitizeTrackingIds,
  type AnalyticsIntegrationsConfig,
} from "@/lib/analytics/services";

const empty: AnalyticsIntegrationsConfig = {
  analytics_provider: "none",
  analytics_id: "",
  gtm_id: "",
  meta_pixel_id: "",
  tiktok_pixel_id: "",
  hubspot_portal_id: "",
};

describe("resolveTrackingServices", () => {
  it("sin nada configurado no hay ningún servicio que consentir", () => {
    expect(resolveTrackingServices(empty).any).toBe(false);
  });

  it("un ID de GA4 con el proveedor en «none» no cuenta", () => {
    const s = resolveTrackingServices({ ...empty, analytics_id: "G-1" });
    expect(s.ga4).toBe(false);
    expect(s.any).toBe(false);
  });

  it("IDs con solo espacios no cuentan", () => {
    expect(resolveTrackingServices({ ...empty, meta_pixel_id: "  " }).any).toBe(false);
  });

  it("GA4 directo es medición", () => {
    const s = resolveTrackingServices({
      ...empty,
      analytics_provider: "ga4",
      analytics_id: "G-1",
    });
    expect(s).toMatchObject({
      ga4: true,
      analytics: true,
      advertising: false,
      any: true,
    });
    expect(consentKind(s)).toBe("analytics");
  });

  it("con GTM no se carga gtag.js en paralelo", () => {
    const s = resolveTrackingServices({
      ...empty,
      analytics_provider: "ga4",
      analytics_id: "G-1",
      gtm_id: "GTM-1",
    });
    expect(s).toMatchObject({ ga4: false, gtm: true, analytics: true });
  });

  it("solo píxeles es publicidad; con medición, ambos", () => {
    const ads = resolveTrackingServices({ ...empty, tiktok_pixel_id: "T1" });
    expect(consentKind(ads)).toBe("ads");
    const both = resolveTrackingServices({
      ...empty,
      gtm_id: "GTM-1",
      meta_pixel_id: "1",
    });
    expect(consentKind(both)).toBe("both");
  });

  it("HubSpot es medición, como clasifica sus propias cookies", () => {
    const s = resolveTrackingServices({ ...empty, hubspot_portal_id: "12345" });
    expect(s).toMatchObject({
      hubspot: true,
      analytics: true,
      advertising: false,
      any: true,
    });
    expect(consentKind(s)).toBe("analytics");
  });
});

describe("sanitizeTrackingIds", () => {
  it("normaliza espacios y mayúsculas de lo que se pega desde el panel", () => {
    const ids = sanitizeTrackingIds({
      ...empty,
      analytics_id: " g-abc123 ",
      gtm_id: "gtm-k9x2 ",
      meta_pixel_id: " 1234567890",
      tiktok_pixel_id: "c4abc ",
      hubspot_portal_id: " 12345678 ",
    });
    expect(ids).toMatchObject({
      analytics_id: "G-ABC123",
      gtm_id: "GTM-K9X2",
      meta_pixel_id: "1234567890",
      tiktok_pixel_id: "C4ABC",
      hubspot_portal_id: "12345678",
    });
  });

  it("un ID con forma inválida se descarta: nunca llega a un script inline", () => {
    const ids = sanitizeTrackingIds({
      ...empty,
      gtm_id: "GTM-1');alert(1);//",
      meta_pixel_id: "12a",
      analytics_id: "UA-1234",
      tiktok_pixel_id: "C4 X",
      hubspot_portal_id: "12345').src='x';//",
    });
    expect(ids).toMatchObject({
      gtm_id: "",
      meta_pixel_id: "",
      analytics_id: "",
      tiktok_pixel_id: "",
      hubspot_portal_id: "",
    });
  });

  it("un ID inválido no activa el servicio ni pide consentimiento", () => {
    expect(resolveTrackingServices({ ...empty, gtm_id: "GTM-1'" }).any).toBe(false);
  });
});
