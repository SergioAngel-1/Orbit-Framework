import { describe, expect, it } from "vitest";
import { editorialTranslatedLocales } from "./editorial-locales";

describe("editorialTranslatedLocales", () => {
  it("el idioma por defecto siempre existe", () => {
    expect(editorialTranslatedLocales({ published: true })).toEqual(["es"]);
    expect(editorialTranslatedLocales(null)).toEqual(["es"]);
  });

  it.each([
    ["el título SEO", { seo: { title_en: "T" } }],
    ["la entradilla", { lead_body_en: "<p>x</p>" }],
    ["el texto de cierre", { closing: { text_en: "<p>x</p>" } }],
    ["el cuerpo de una sección", { sections: { s3: { body_en: "<p>x</p>" } } }],
  ])("un idioma existe si trae traducción de %s", (_name, cfg) => {
    expect(editorialTranslatedLocales(cfg)).toEqual(["es", "en"]);
  });

  it("lo que no es de peso o está en blanco no anuncia el idioma", () => {
    expect(
      editorialTranslatedLocales({
        seo: { title_en: "   " },
        faq: { title_en: "Questions" },
        closing: { cta_label_en: "Go" },
      }),
    ).toEqual(["es"]);
  });
});
