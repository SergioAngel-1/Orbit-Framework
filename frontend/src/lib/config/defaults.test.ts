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
  it("trae los 11 módulos del catálogo, todos apagados", () => {
    expect(Object.keys(CONFIG_DEFAULTS.modules).sort()).toEqual([...MODULE_IDS].sort());
    for (const id of MODULE_IDS) {
      expect(CONFIG_DEFAULTS.modules[id]).toBe(false);
    }
    expect(CONFIG_DEFAULTS.modules.loyalty).toBe(false);
    expect(CONFIG_DEFAULTS.modules.memberships).toBe(false);
  });

  it("el envío por distancia viene apagado por defecto (lo enciende el módulo)", () => {
    expect(CONFIG_DEFAULTS.shipping).toEqual({ distance_enabled: false });
  });

  it("trae `instance` (vacío en el framework)", () => {
    expect(CONFIG_DEFAULTS.instance).toEqual({});
  });

  it("cubre los grupos públicos del esquema", () => {
    const keys = leafKeys(CONFIG_DEFAULTS);
    for (const required of [
      "brand.name",
      "brand.email_logo",
      "social.whatsapp",
      "legal.phone",
      "legal.mobile",
      "legal.locations.location_1.label",
      "legal.locations.location_5.longitude",
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
      "seo.business_type",
      "geo.faq_payments",
      "i18n.locales",
      "loyalty.points_enabled",
      "loyalty.currency_name_plural",
      "loyalty.events.registration",
      "loyalty.events.review",
      "loyalty.events.birthday",
      "loyalty.transfer_daily_cap",
      "memberships.card_enabled",
      "memberships.card_verify_path",
      "memberships.member_number_prefix",
      "shipping.distance_enabled",
    ]) {
      expect(keys, required).toContain(required);
    }
    expect(keys).not.toContain("brand.og_image");
    expect(keys).not.toContain("loyalty.allowed_roles");
    expect(keys).not.toContain("memberships.card_token_ttl_hours");
    expect(keys).not.toContain("memberships.expiring_notice_days");
    expect(keys).not.toContain("memberships.referral_gift_requires_email_verification");
  });

  // El grupo `loyalty` lo puebla el plugin `hwe-loyalty` en el Control Center. Sus defaults
  // son el espejo de `HWE_Loyalty_Settings::DEFAULTS` (PHP): si uno cambia y el otro no, este
  // test lo dice con la ruta exacta (el lado PHP lo fija `backend/tests/Unit/Loyalty/SettingsTest.php`).
  it("trae los defaults del módulo loyalty en paridad con el Schema", () => {
    expect(CONFIG_DEFAULTS.loyalty).toEqual({
      points_enabled: false,
      referrals_enabled: false,
      currency_name: "punto",
      currency_name_plural: "puntos",
      currency_icon: "",
      point_value: "0.01",
      min_redeem: "100",
      max_redeem_per_order: "0",
      full_payment_enabled: false,
      min_cash_due: "0.01",
      expiry_days: "0",
      cashback_enabled: false,
      cashback_percent: "0",
      events: {
        registration: "0",
        review: "0",
        birthday: "0",
      },
      referral_signup_points_l1: "0",
      referral_signup_points_l2: "0",
      commission_first_percent: "0",
      commission_next_percent: "0",
      level2_enabled: false,
      commission_level2_percent: "0",
      commission_duration_days: "0",
      transfers_enabled: false,
      transfer_min: "100",
      transfer_fee_percent: "0",
      transfer_daily_cap: "0",
    });
  });

  // El grupo `memberships` lo puebla el plugin `hwe-memberships` en el Control Center. Solo sus
  // campos públicos cruzan a la config: el TTL del token, los días de aviso y la exigencia de
  // correo verificado son ajustes operativos del backend.
  it("trae los defaults públicos del módulo memberships en paridad con el Schema", () => {
    expect(CONFIG_DEFAULTS.memberships).toEqual({
      card_enabled: false,
      card_verify_path: "/verify",
      member_number_prefix: "",
    });
  });
});
