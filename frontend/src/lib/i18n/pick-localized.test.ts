import { describe, expect, it } from "vitest";
import { localeSuffix, pickLocalized } from "./pick-localized";

const brand = {
  tagline: "Hola",
  tagline_en: "Hello",
  tagline_fr: "",
  tagline_pt_br: "Olá",
};

describe("pickLocalized", () => {
  it("en el idioma por defecto devuelve el campo base", () => {
    expect(pickLocalized(brand, "tagline", "es")).toBe("Hola");
  });

  it("en un idioma secundario usa su variante", () => {
    expect(pickLocalized(brand, "tagline", "en")).toBe("Hello");
    expect(pickLocalized(brand, "tagline", "pt-BR")).toBe("Olá");
  });

  it("sin variante, o vacía, cae al idioma por defecto (nunca a otro secundario)", () => {
    expect(pickLocalized(brand, "tagline", "fr")).toBe("Hola");
    expect(pickLocalized(brand, "tagline", "de")).toBe("Hola");
    expect(pickLocalized({ tagline: "Hola", tagline_en: "   " }, "tagline", "en")).toBe(
      "Hola",
    );
  });

  it("sin valor devuelve null", () => {
    expect(pickLocalized(null, "tagline", "en")).toBeNull();
    expect(pickLocalized({}, "tagline", "es")).toBeNull();
    expect(pickLocalized({ tagline_en: "Hello" }, "tagline", "en")).toBe("Hello");
  });
});

describe("localeSuffix", () => {
  it("normaliza como el Control Center", () => {
    expect(localeSuffix("pt-BR")).toBe("pt_br");
    expect(localeSuffix(" EN ")).toBe("en");
  });
});
