import { describe, expect, it } from "vitest";
import { landingContentLocale, landingTranslatedLocales } from "./localized";

// El fixture de `routing` en los tests es es/en (ver `src/i18n/routing.ts`).

describe("landingTranslatedLocales", () => {
  it("el idioma por defecto siempre existe", () => {
    expect(landingTranslatedLocales({ heroSubtitle: "Hola" })).toEqual(["es"]);
    expect(landingTranslatedLocales(null)).toEqual(["es"]);
  });

  it("un idioma secundario existe si algún campo de peso está traducido", () => {
    expect(landingTranslatedLocales({ hero_subtitle_en: "Hi" })).toEqual(["es", "en"]);
    expect(landingTranslatedLocales({ closing_text_en: "Bye" })).toEqual(["es", "en"]);
  });

  it("un campo que no es de peso no anuncia el idioma", () => {
    expect(landingTranslatedLocales({ cta_title_en: "Buy" })).toEqual(["es"]);
  });

  it("una traducción en blanco no cuenta", () => {
    expect(landingTranslatedLocales({ seo_title_en: "   " })).toEqual(["es"]);
  });
});

describe("landingContentLocale", () => {
  it("sirve el idioma pedido si existe y el por defecto si no", () => {
    expect(landingContentLocale({ seo_title_en: "T" }, "en")).toBe("en");
    expect(landingContentLocale({ heroSubtitle: "Hola" }, "en")).toBe("es");
    expect(landingContentLocale({}, "es")).toBe("es");
  });
});
