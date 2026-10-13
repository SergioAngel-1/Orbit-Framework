import { describe, it, expect } from "vitest";
import {
  absoluteLocalized,
  alternatesFor,
  entityPaths,
  hreflangLanguages,
  localizedPath,
} from "./urls";

describe("localizedPath", () => {
  it("el locale por defecto no lleva prefijo; el resto sí", () => {
    expect(localizedPath("/blog", "es")).toBe("/blog");
    expect(localizedPath("/blog", "en")).toBe("/en/blog");
  });

  it("la home es «/» y «/en»", () => {
    expect(localizedPath("/", "es")).toBe("/");
    expect(localizedPath("/", "en")).toBe("/en");
  });
});

describe("hreflangLanguages / alternatesFor", () => {
  it("declara todos los idiomas y x-default", () => {
    expect(hreflangLanguages("/products")).toEqual({
      es: "/products",
      en: "/en/products",
      "x-default": "/products",
    });
  });

  it("una entidad sin traducción no declara el idioma que no tiene", () => {
    expect(hreflangLanguages("/products/x", { locales: ["es"] })).toEqual({
      es: "/products/x",
      "x-default": "/products/x",
    });
  });

  it("en un idioma sin traducción, la canónica es la del idioma por defecto", () => {
    // /en/products/x sirve el MISMO contenido que /products/x: su canónica es la
    // española, no una propia (sería un duplicado exacto).
    expect(alternatesFor("/products/x", "en", { locales: ["es"] }).canonical).toBe(
      "/products/x",
    );
    expect(alternatesFor("/products/x", "en").canonical).toBe("/en/products/x");
  });

  it("con traducción, cada idioma es su propia canónica", () => {
    expect(
      alternatesFor("/products/x", "en", { locales: ["es", "en"] }).canonical,
    ).toBe("/en/products/x");
  });
});

describe("slug distinto por idioma (`paths`)", () => {
  const options = { paths: { es: "/blog/mi-entrada", en: "/blog/my-post" } };

  it("cada idioma declara su propio tramo", () => {
    expect(hreflangLanguages("/blog/mi-entrada", options)).toEqual({
      es: "/blog/mi-entrada",
      en: "/en/blog/my-post",
      "x-default": "/blog/mi-entrada",
    });
  });

  it("la canónica es la URL de ese idioma", () => {
    expect(alternatesFor("/blog/mi-entrada", "en", options).canonical).toBe(
      "/en/blog/my-post",
    );
    expect(alternatesFor("/blog/mi-entrada", "es", options).canonical).toBe(
      "/blog/mi-entrada",
    );
  });

  it("un idioma sin entrada en `paths` usa la ruta base", () => {
    expect(hreflangLanguages("/blog/x", { paths: { en: "/blog/y" } })).toEqual({
      es: "/blog/x",
      en: "/en/blog/y",
      "x-default": "/blog/x",
    });
  });

  it("sin traducción, la canónica de /en es la del idioma por defecto", () => {
    expect(
      alternatesFor("/blog/mi-entrada", "en", { ...options, locales: ["es"] })
        .canonical,
    ).toBe("/blog/mi-entrada");
  });
});

describe("absoluteLocalized", () => {
  it("compone la URL absoluta sin doble barra", () => {
    expect(absoluteLocalized("https://x.example/", "/blog", "en")).toBe(
      "https://x.example/en/blog",
    );
  });
});

describe("entityPaths", () => {
  it("antepone el prefijo al tramo de cada idioma y lo escapa", () => {
    expect(entityPaths("/products", { es: "camiseta", en: "t shirt" })).toEqual({
      es: "/products/camiseta",
      en: "/products/t%20shirt",
    });
  });

  it("sin tramos por idioma no declara ninguno", () => {
    expect(entityPaths("/products", undefined)).toEqual({});
  });

  it("alimenta el hreflang con el slug traducido de cada idioma", () => {
    const paths = entityPaths("/products", { es: "camiseta", en: "t-shirt" });

    expect(alternatesFor("/products/camiseta", "en", { paths })).toEqual({
      canonical: "/en/products/t-shirt",
      languages: {
        es: "/products/camiseta",
        en: "/en/products/t-shirt",
        "x-default": "/products/camiseta",
      },
    });
  });
});
