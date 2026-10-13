import { describe, expect, it } from "vitest";
import { productLearnHref, productLearnPaths } from "./product-learn-href";

describe("productLearnHref", () => {
  it("cuelga de la ficha con su tramo de URL del idioma", () => {
    expect(productLearnHref({ slug: "camiseta", urlSlug: "t-shirt" })).toBe(
      "/products/t-shirt/learn",
    );
  });

  it("sin tramo traducido usa el slug nativo", () => {
    expect(productLearnHref({ slug: "camiseta" })).toBe("/products/camiseta/learn");
    expect(productLearnHref({ slug: "camiseta", urlSlug: null })).toBe(
      "/products/camiseta/learn",
    );
  });

  it("codifica el tramo", () => {
    expect(productLearnHref({ slug: "a b/c" })).toBe("/products/a%20b%2Fc/learn");
  });
});

describe("productLearnPaths", () => {
  it("da la ruta de la landing en cada idioma", () => {
    expect(productLearnPaths({ es: "camiseta", en: "t-shirt" })).toEqual({
      es: "/products/camiseta/learn",
      en: "/products/t-shirt/learn",
    });
  });
});
