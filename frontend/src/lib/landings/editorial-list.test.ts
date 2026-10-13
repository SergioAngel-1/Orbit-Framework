import { describe, expect, it } from "vitest";
import { publishedEditorialLandings } from "./editorial-list";

const config = (editorial: Record<string, unknown>, enabled = true) =>
  ({
    modules: { landings: enabled },
    landings: {
      registry:
        "guia | Guía | article\nperfil | Perfil | person\nborrador | Borrador | article",
      editorial,
    },
  }) as never;

const EDITORIAL = {
  guia: {
    published: true,
    seo: { title: "Guía de uso", title_en: "Usage guide", description: "Cómo se usa" },
    url: { base: "area", path: "guia-de-uso", path_en: "usage-guide" },
  },
  perfil: { published: true, url: { base: "root", path: "mi-perfil" } },
  borrador: { published: false },
};

describe("publishedEditorialLandings", () => {
  it("lista las publicadas con su ruta y su título (o la etiqueta del registro)", () => {
    const list = publishedEditorialLandings(config(EDITORIAL), "es");

    expect(list).toEqual([
      {
        slug: "guia",
        title: "Guía de uso",
        description: "Cómo se usa",
        href: "/learn/guia-de-uso",
        base: "area",
      },
      {
        slug: "perfil",
        title: "Perfil",
        description: null,
        href: "/mi-perfil",
        base: "root",
      },
    ]);
  });

  it("filtra por ubicación", () => {
    const list = publishedEditorialLandings(config(EDITORIAL), "es", "root");

    expect(list.map((item) => item.slug)).toEqual(["perfil"]);
  });

  it("solo anuncia un idioma en el que la landing existe", () => {
    const withoutEnglish = config({
      guia: { published: true, seo: { title: "Guía de uso" } },
      perfil: EDITORIAL.perfil,
    });
    const withEnglish = config({
      guia: {
        published: true,
        seo: { title: "Guía de uso" },
        lead_body_en: "<p>Hi</p>",
      },
      perfil: EDITORIAL.perfil,
    });

    expect(publishedEditorialLandings(withoutEnglish, "en")).toEqual([]);
    expect(
      publishedEditorialLandings(withEnglish, "en").map((item) => item.slug),
    ).toEqual(["guia"]);
  });

  it("con el módulo apagado, o sin sección landings, no hay nada", () => {
    expect(publishedEditorialLandings(config(EDITORIAL, false), "es")).toEqual([]);
    expect(publishedEditorialLandings({ modules: {} } as never, "es")).toEqual([]);
  });
});
