import { describe, expect, it } from "vitest";
import { brandDescription, brandTagline } from "./brand";
import { businessPhone } from "./contact";
import { CONFIG_DEFAULTS } from "./defaults";

const withBrand = (brand: Record<string, string>) => ({
  ...CONFIG_DEFAULTS,
  brand: { ...CONFIG_DEFAULTS.brand, ...brand },
});

describe("brandTagline / brandDescription", () => {
  it("resuelven al locale con caída al español", () => {
    const config = withBrand({ tagline: "ES", tagline_en: "EN" });
    expect(brandTagline(config, "en")).toBe("EN");
    expect(brandTagline(config, "es")).toBe("ES");
  });

  it("sin traducción inglesa heredan el español", () => {
    const config = withBrand({ description: "ES", description_en: "" });
    expect(brandDescription(config, "en")).toBe("ES");
  });
});

describe("businessPhone", () => {
  it("prefiere el fijo y cae al celular", () => {
    const config = {
      ...CONFIG_DEFAULTS,
      legal: { ...CONFIG_DEFAULTS.legal, phone: "111", mobile: "222" },
    };
    expect(businessPhone(config)).toBe("111");
  });

  it("sin fijo usa el celular; sin ninguno, vacío", () => {
    const noPhone = {
      ...CONFIG_DEFAULTS,
      legal: { ...CONFIG_DEFAULTS.legal, phone: "", mobile: "222" },
    };
    expect(businessPhone(noPhone)).toBe("222");
    const none = {
      ...CONFIG_DEFAULTS,
      legal: { ...CONFIG_DEFAULTS.legal, phone: "", mobile: "" },
    };
    expect(businessPhone(none)).toBe("");
  });
});
