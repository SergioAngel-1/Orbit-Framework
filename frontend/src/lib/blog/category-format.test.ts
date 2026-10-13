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

describe("toBlogCategory", () => {
  it("`count` null es 0 y una descripción en blanco, null", () => {
    expect(
      toBlogCategory({ name: "Guías", slug: "guias", count: null, description: "  " }),
    ).toEqual({ name: "Guías", slug: "guias", description: null, count: 0 });
  });
});
