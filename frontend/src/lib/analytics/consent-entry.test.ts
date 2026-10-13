import { describe, expect, it } from "vitest";
import { buildConsentLedgerEntry, consentTextHash } from "./consent-entry";
import { resolveTrackingServices } from "./services";
import type { ConsentRecordRequest } from "./consent-record";

const RECEIPT = "3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c";
const services = resolveTrackingServices({
  analytics_provider: "ga4",
  analytics_id: "G-TEST1",
  gtm_id: "",
  meta_pixel_id: "123",
  tiktok_pixel_id: "",
  hubspot_portal_id: "",
});
const request: ConsentRecordRequest = {
  decision: "accepted",
  action: "first",
  path: "/tienda",
  variant: "bar",
  locale: "es",
  clientAt: null,
  services: ["ga4", "meta"],
  generation: 4,
};

function build(over: Partial<Parameters<typeof buildConsentLedgerEntry>[0]> = {}) {
  return buildConsentLedgerEntry({
    receipt: RECEIPT,
    userId: null,
    request,
    services,
    text: "Esta web usa cookies…",
    ip: "181.49.23.0",
    userAgent: "Mozilla/5.0",
    ...over,
  });
}

describe("buildConsentLedgerEntry", () => {
  it("aceptar concede las categorías que el banner pedía, con sus servicios", () => {
    const entry = build();
    expect(entry).toMatchObject({
      receipt: RECEIPT,
      user_id: null,
      decision: "accepted",
      analytics: true,
      advertising: true,
      services: ["ga4", "meta"],
      text_hash: consentTextHash("Esta web usa cookies…"),
    });
  });

  it("«Solo necesarias» no concede ninguna, pero deja constancia de lo que se pidió", () => {
    const entry = build({ request: { ...request, decision: "declined" } });
    expect(entry).toMatchObject({
      analytics: false,
      advertising: false,
      services: ["ga4", "meta"],
    });
  });

  it("sin publicidad configurada, aceptar no la concede", () => {
    const onlyGa = resolveTrackingServices({
      analytics_provider: "ga4",
      analytics_id: "G-TEST1",
      gtm_id: "",
      meta_pixel_id: "",
      tiktok_pixel_id: "",
      hubspot_portal_id: "",
    });
    expect(build({ services: onlyGa })).toMatchObject({
      analytics: true,
      advertising: false,
    });
  });

  it("el usuario sale de la sesión como número; basura = anónimo", () => {
    expect(build({ userId: "42" }).user_id).toBe(42);
    expect(build({ userId: "dXNlcjo0Mg==" }).user_id).toBeNull();
    expect(build({ userId: "0" }).user_id).toBeNull();
  });

  it("el hash es estable y distingue textos distintos", () => {
    expect(consentTextHash("a")).toBe(consentTextHash("a"));
    expect(consentTextHash("a")).not.toBe(consentTextHash("b"));
    expect(consentTextHash("a")).toMatch(/^[0-9a-f]{64}$/);
  });
});
