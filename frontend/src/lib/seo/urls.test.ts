import { describe, it, expect } from "vitest";
import {
  absoluteLocalized,
  alternatesFor,
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

describe("absoluteLocalized", () => {
  it("compone la URL absoluta sin doble barra", () => {
    expect(absoluteLocalized("https://x.example/", "/blog", "en")).toBe(
      "https://x.example/en/blog",
    );
  });
});
