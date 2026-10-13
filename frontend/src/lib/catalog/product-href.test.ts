import { describe, expect, it } from "vitest";
import { categoryHref, productHref } from "./product-href";

describe("productHref", () => {
  it("usa el slug de URL del idioma de la página cuando la lectura lo resolvió", () => {
    expect(productHref({ slug: "camiseta", urlSlug: "t-shirt" })).toBe(
      "/products/t-shirt",
    );
  });

  it("sin slug de URL usa el nativo (la ficha redirige al del idioma)", () => {
    expect(productHref({ slug: "camiseta" })).toBe("/products/camiseta");
    expect(productHref({ slug: "camiseta", urlSlug: null })).toBe("/products/camiseta");
    expect(productHref({ slug: "camiseta", urlSlug: "" })).toBe("/products/camiseta");
  });

  it("codifica el tramo: un slug con caracteres raros no rompe la ruta", () => {
    expect(productHref({ slug: "a b/c" })).toBe("/products/a%20b%2Fc");
  });
});

describe("categoryHref", () => {
  it("igual que el producto: el slug de URL si existe, si no el nativo", () => {
    expect(categoryHref({ slug: "amor-propio", urlSlug: "self-love" })).toBe(
      "/categories/self-love",
    );
    expect(categoryHref({ slug: "amor-propio" })).toBe("/categories/amor-propio");
  });
});
