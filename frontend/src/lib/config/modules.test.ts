import { describe, expect, it } from "vitest";
import { isModuleEnabled, MODULE_IDS } from "./modules";
import { CONFIG_DEFAULTS } from "./defaults";

describe("isModuleEnabled", () => {
  it("devuelve false para todo con los defaults (ningún módulo activo)", () => {
    for (const id of MODULE_IDS) {
      expect(isModuleEnabled(CONFIG_DEFAULTS, id)).toBe(false);
    }
  });

  it("devuelve true solo para el módulo activo", () => {
    const config = {
      modules: { ...CONFIG_DEFAULTS.modules, banners: true },
    };
    expect(isModuleEnabled(config, "banners")).toBe(true);
    expect(isModuleEnabled(config, "shipping")).toBe(false);
  });

  it("el catálogo tiene los 11 ids del contrato", () => {
    expect([...MODULE_IDS].sort()).toEqual(
      [
        "banners",
        "shipping",
        "product-landing",
        "landings",
        "consent-log",
        "site-reviews",
        "coupon-rules",
        "customer-identity",
        "external-integration",
        "loyalty",
        "memberships",
      ].sort(),
    );
  });
});
