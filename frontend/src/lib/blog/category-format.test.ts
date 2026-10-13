import { describe, expect, it } from "vitest";
import {
  blogCategoryHref,
  categoryPageAvailable,
  isLandingCategory,
  toBlogCategory,
} from "./category-format";

describe("isLandingCategory", () => {
  it("una categoría temática tiene página propia", () => {
    expect(isLandingCategory("guias")).toBe(true);
  });

  it("el cajón por defecto de WordPress no, en cualquier idioma de instalación", () => {
    expect(isLandingCategory("uncategorized")).toBe(false);
    expect(isLandingCategory("Uncategorized")).toBe(false);
    expect(isLandingCategory("sin-categoria")).toBe(false);
  });

  it("un slug vacío no es una categoría", () => {
    expect(isLandingCategory("")).toBe(false);
    expect(isLandingCategory("  ")).toBe(false);
  });
});

describe("blogCategoryHref", () => {
  it("enlaza a la página de la categoría, con el slug codificado", () => {
    expect(blogCategoryHref("guias")).toBe("/blog/category/guias");
    expect(blogCategoryHref("a b")).toBe("/blog/category/a%20b");
  });

  it("un cajón vuelve al listado", () => {
    expect(blogCategoryHref("uncategorized")).toBe("/blog");
  });
});

describe("categoryPageAvailable", () => {
  const category = toBlogCategory({ name: "Guías", slug: "guias", count: 3 });

  it("con entradas y no siendo un cajón, se sirve", () => {
    expect(categoryPageAvailable(category)).toBe(true);
  });

  it("vacía, cajón o inexistente: 404", () => {
    expect(categoryPageAvailable({ ...category, count: 0 })).toBe(false);
    expect(categoryPageAvailable({ ...category, slug: "uncategorized" })).toBe(false);
    expect(categoryPageAvailable(null)).toBe(false);
  });
});

describe("toBlogCategory — idiomas", () => {
  const raw = {
    name: "Guías",
    slug: "guias",
    count: 3,
    description: "Todo.",
    name_en: "Guides",
    description_en: "Everything.",
  };

  it("pinta el nombre y la descripción del idioma de la página", () => {
    expect(toBlogCategory(raw, "en")).toMatchObject({
      name: "Guides",
      description: "Everything.",
    });
    expect(toBlogCategory(raw, "es")).toMatchObject({
      name: "Guías",
      description: "Todo.",
    });
  });

  it("sin descripción traducida cae a la nativa", () => {
    const { description_en: _omitted, ...withoutDescription } = raw;
    void _omitted;

    expect(toBlogCategory(withoutDescription, "en").description).toBe("Todo.");
  });

  it("sin nombre traducido la página existe solo en el idioma por defecto", () => {
    const { name_en: _omitted, ...withoutName } = raw;
    void _omitted;

    expect(toBlogCategory(withoutName, "es").locales).toEqual(["es"]);
    expect(toBlogCategory(raw, "es").locales).toEqual(["es", "en"]);
  });
});

describe("toBlogCategory", () => {
  it("`count` null es 0 y una descripción en blanco, null", () => {
    expect(
      toBlogCategory({ name: "Guías", slug: "guias", count: null, description: "  " }),
    ).toEqual({
      name: "Guías",
      slug: "guias",
      description: null,
      count: 0,
      locales: ["es"],
    });
  });
});
