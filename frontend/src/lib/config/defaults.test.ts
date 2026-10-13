import { describe, expect, it } from "vitest";
import { CONFIG_DEFAULTS } from "./defaults";
import { MODULE_IDS } from "./modules";

/** Claves hoja de un objeto, en forma `a.b.c` (los arrays cuentan como hoja). */
function leafKeys(value: unknown, prefix = ""): string[] {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return [prefix];
  }
  return Object.entries(value as Record<string, unknown>).flatMap(([key, child]) =>
    leafKeys(child, prefix ? `${prefix}.${key}` : key),
  );
}

// El contrato de la tarea 33: Schema.php, CONFIG_DEFAULTS y SiteConfig van
// sincronizados. Este test caza el olvido en el acto: si el esquema gana un campo y
// aquí no aparece (o al revés), falla con la ruta exacta.
describe("CONFIG_DEFAULTS", () => {
  it("trae los 9 módulos del catálogo, todos apagados", () => {
    expect(Object.keys(CONFIG_DEFAULTS.modules).sort()).toEqual([...MODULE_IDS].sort());
    for (const id of MODULE_IDS) {
      expect(CONFIG_DEFAULTS.modules[id]).toBe(false);
    }
  });

  it("trae `instance` (vacío en el framework)", () => {
    expect(CONFIG_DEFAULTS.instance).toEqual({});
  });

  it("cubre los grupos públicos del esquema", () => {
    const keys = leafKeys(CONFIG_DEFAULTS);
    for (const required of [
      "brand.name",
      "brand.tagline_en",
      "brand.description_en",
      "brand.email_logo",
      "social.whatsapp",
      "legal.phone",
      "legal.mobile",
      "legal.locations.location_1.label",
      "legal.locations.location_4.longitude",
      "ecommerce.checkout_login_required",
      "ecommerce.free_shipping.enabled",
      "ecommerce.free_shipping.min_amount",
      "payments.gateway_enabled",
      "payments.whatsapp_enabled",
      "payments.cash_enabled",
      "integrations.gtm_id",
      "integrations.meta_pixel_id",
      "integrations.tiktok_pixel_id",
      "integrations.hubspot_portal_id",
      "seo.og_image",
      "seo.return_method",
      "seo.return_fees",
      "seo.handling_days",
      "seo.transit_days",
      "seo.alternate_names",
      "geo.faq_en",
      "geo.faq_payments",
      "geo.faq_payments_en",
    ]) {
      expect(keys, required).toContain(required);
    }
    expect(keys).not.toContain("brand.og_image");
  });
});
