import { describe, expect, it, vi } from "vitest";

// Tres idiomas: el por defecto y dos secundarios, uno con subetiqueta regional.
vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en", "pt-BR"], defaultLocale: "es" },
}));

import {
  TRANSLATION_FIELDS,
  flattenTranslations,
  hasTranslation,
  pickTranslated,
  secondaryLocales,
  stripTranslations,
  translatedLocales,
  translationLocales,
  withTranslations,
} from "./translation";

describe("idiomas", () => {
  it("los secundarios son los de routing sin el idioma por defecto", () => {
    expect(secondaryLocales()).toEqual(["en", "pt-BR"]);
  });

  it("una página en el idioma por defecto no pide traducciones; en un secundario, las suyas", () => {
    expect(translationLocales("es")).toEqual([]);
    expect(translationLocales("en")).toEqual(["en"]);
    expect(translationLocales("pt-BR")).toEqual(["pt-BR"]);
  });

  it("un idioma que el sitio no declara no pide nada (nunca llega a la query)", () => {
    expect(translationLocales("xx")).toEqual([]);
    expect(translationLocales('en") { x } #')).toEqual([]);
  });
});

describe("withTranslations", () => {
  const doc = `
    query Post($slug: ID!) {
      post(id: $slug, idType: SLUG) {
        title
        # @translation Post
        slug
      }
    }`;

  it("sustituye el marcador por una selección con alias por idioma", () => {
    const out = withTranslations(doc, ["en", "pt-BR"]);

    expect(out).toContain(
      'tr_en: translation(locale: "en") { title excerpt content seoTitle seoDescription slug }',
    );
    expect(out).toContain('tr_pt_br: translation(locale: "pt-BR") {');
    expect(out).not.toContain("@translation");
  });

  it("sin idiomas quita el marcador: la query no menciona `translation`", () => {
    const out = withTranslations(doc, []);

    expect(out).not.toContain("translation");
    expect(out).toContain("title");
    expect(out).toContain("slug");
  });

  it("admite una lista de campos propia (tarjetas: solo lo que se pinta)", () => {
    const out = withTranslations(
      "{\n  x\n  # @translation Post: title excerpt slug\n}",
      ["en"],
    );

    expect(out).toContain('tr_en: translation(locale: "en") { title excerpt slug }');
  });

  it("descarta los idiomas que el sitio no declara", () => {
    const out = withTranslations(doc, ["en", "xx"]);

    expect(out).toContain("tr_en");
    expect(out).not.toContain("xx");
  });

  it("no pide traducción del idioma por defecto", () => {
    const out = withTranslations(doc, ["es"]);

    expect(out).not.toContain("translation");
  });

  it("un tipo sin campos declarados es un error de programación", () => {
    expect(() => withTranslations("{\n  # @translation Nada\n}", ["en"])).toThrow(
      /Nada/,
    );
  });

  it("respeta los demás comentarios de la query", () => {
    const out = withTranslations("{\n  # first 50 no es decorativo\n  a\n}", ["en"]);

    expect(out).toContain("# first 50 no es decorativo");
  });

  it("los campos por tipo son los que registra hwe-content-i18n", () => {
    expect(TRANSLATION_FIELDS.Post).toEqual([
      "title",
      "excerpt",
      "content",
      "seoTitle",
      "seoDescription",
      "slug",
    ]);
    expect(TRANSLATION_FIELDS.Tag).toEqual(["name"]);
  });
});

describe("flattenTranslations", () => {
  it("convierte `tr_<idioma>` en `<campo>_<idioma>` (lo que lee pickLocalized)", () => {
    const out = flattenTranslations({
      title: "Hola",
      tr_en: { title: "Hello", seoTitle: "SEO en", excerpt: null },
      tr_pt_br: { title: "Olá", slug: "ola" },
    });

    expect(out).toEqual({
      title: "Hola",
      title_en: "Hello",
      seo_title_en: "SEO en",
      title_pt_br: "Olá",
      slug_pt_br: "ola",
    });
  });

  it("omite lo nulo y lo vacío: sin traducción no hay clave", () => {
    const out = flattenTranslations({
      tr_en: { title: "   ", content: "" },
      tr_fr: null,
    });

    expect(out).toEqual({});
  });

  it("recorre listas y objetos anidados", () => {
    const out = flattenTranslations({
      posts: {
        nodes: [
          {
            title: "A",
            tr_en: { title: "A en" },
            tags: { nodes: [{ name: "t", tr_en: { name: "t en" } }] },
          },
        ],
      },
    });

    expect(out).toEqual({
      posts: {
        nodes: [
          {
            title: "A",
            title_en: "A en",
            tags: { nodes: [{ name: "t", name_en: "t en" }] },
          },
        ],
      },
    });
  });

  it("no muta la entrada ni toca lo que no es traducción", () => {
    const input = { n: 1, ok: true, s: "x", nada: null, tr_en: { title: "T" } };
    const copy = JSON.parse(JSON.stringify(input));

    const out = flattenTranslations(input);

    expect(input).toEqual(copy);
    expect(out).toEqual({ n: 1, ok: true, s: "x", nada: null, title_en: "T" });
  });
});

describe("stripTranslations", () => {
  it("quita las claves de los idiomas secundarios, también en lo anidado", () => {
    const out = stripTranslations({
      title: "Hola",
      title_en: "Hello",
      seo_title_pt_br: "x",
      tags: { nodes: [{ name: "a", name_en: "a en" }] },
    });

    expect(out).toEqual({ title: "Hola", tags: { nodes: [{ name: "a" }] } });
  });

  it("no toca claves que solo se parecen", () => {
    const entity = { len: 1, plan_b: 2, slug_en_plural: 3 };

    expect(stripTranslations(entity)).toEqual(entity);
  });
});

describe("hasTranslation / translatedLocales", () => {
  const entity = { name: "Amor", name_en: "Love", name_pt_br: "  ", name_fr: "Amour" };

  it("el idioma por defecto siempre existe", () => {
    expect(hasTranslation({}, "es", "name")).toBe(true);
  });

  it("un secundario existe si su campo principal tiene texto", () => {
    expect(hasTranslation(entity, "en", "name")).toBe(true);
    expect(hasTranslation(entity, "pt-BR", "name")).toBe(false);
    expect(hasTranslation(entity, "de", "name")).toBe(false);
  });

  it("lista los idiomas del sitio en los que la entidad existe, con el por defecto primero", () => {
    expect(translatedLocales(entity, "name")).toEqual(["es", "en"]);
    expect(translatedLocales({ name: "x" }, "name")).toEqual(["es"]);
  });
});

describe("pickTranslated", () => {
  const entity = {
    seoTitle: "SEO es",
    name: "Camiseta",
    seo_title_en: "SEO en",
    name_pt_br: " ",
  };

  it("en el idioma por defecto da el texto nativo", () => {
    expect(pickTranslated(entity, "seoTitle", "es")).toBe("SEO es");
  });

  it("en un secundario lee la variante aplanada con el nombre de GraphQL", () => {
    expect(pickTranslated(entity, "seoTitle", "en")).toBe("SEO en");
  });

  it("sin traducción o con texto vacío cae al nativo, nunca a otro idioma", () => {
    expect(pickTranslated(entity, "name", "pt-BR")).toBe("Camiseta");
    expect(pickTranslated(entity, "seoTitle", "pt-BR")).toBe("SEO es");
  });

  it("sin entidad o sin campo es null", () => {
    expect(pickTranslated(null, "name", "en")).toBeNull();
    expect(pickTranslated(entity, "nada", "en")).toBeNull();
  });
});
