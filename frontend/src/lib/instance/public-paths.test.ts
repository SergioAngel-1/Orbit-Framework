import { describe, expect, it } from "vitest";
import { CORE_ROOT_SEGMENTS } from "@/lib/landings/core-segments";
import {
  publicAliases,
  publicPathEntries,
  publicRootSegments,
  toInternalPath,
  toPublicPath,
  validatePublicPaths,
} from "./public-paths";
import type { PublicPathMap } from "./types";

// ============================================================================
//  Traducción ruta interna ↔ ruta pública de la instancia.
//
//  Sin mapa, identidad (el framework no cambia ninguna URL). Con mapa, una instancia
//  sirve las carpetas de `app/[locale]/` con sus tramos, incluidos los hijos (heredan el
//  prefijo) y las rutas concretas con parámetros.
// ============================================================================

const MAP: PublicPathMap = {
  "/products": "/productos",
  "/categories": { es: "/categorias", en: "/categories" },
  "/cart": "/carrito",
  "/checkout": "/pago",
  "/account": "/cuenta",
  "/login": "/entrar",
  "/register": "/registro",
  "/blog": "/novedades",
  "/blog/archive": "/archivo",
};

describe("sin mapa", () => {
  it("es la identidad, con consulta y ancla", () => {
    expect(toPublicPath("/products/vela?x=1#top", "es", {})).toBe(
      "/products/vela?x=1#top",
    );
    expect(toInternalPath("/productos/vela", "es", {})).toBe("/productos/vela");
  });

  it("no valida nada", () => {
    expect(() => validatePublicPaths(undefined)).not.toThrow();
    expect(() => validatePublicPaths({})).not.toThrow();
  });
});

describe("toPublicPath", () => {
  it("traduce una ruta concreta y conserva consulta y ancla", () => {
    expect(toPublicPath("/products/vela-roja", "es", MAP)).toBe("/productos/vela-roja");
    expect(toPublicPath("/cart?step=2#resumen", "es", MAP)).toBe(
      "/carrito?step=2#resumen",
    );
  });

  it("un hijo hereda el prefijo del padre", () => {
    expect(toPublicPath("/products/vela-roja/learn", "es", MAP)).toBe(
      "/productos/vela-roja/learn",
    );
    expect(toPublicPath("/account/orders/12", "es", MAP)).toBe("/cuenta/orders/12");
  });

  it("una entrada propia manda sobre la herencia del padre", () => {
    expect(toPublicPath("/blog/archive", "es", MAP)).toBe("/archivo");
    expect(toPublicPath("/blog/mi-entrada", "es", MAP)).toBe("/novedades/mi-entrada");
  });

  it("respeta el mapa por idioma y deja intacto un idioma sin entrada", () => {
    expect(toPublicPath("/categories/velas", "es", MAP)).toBe("/categorias/velas");
    expect(toPublicPath("/categories/velas", "en", MAP)).toBe("/categories/velas");
    expect(toPublicPath("/cart", "en", MAP)).toBe("/carrito");
  });

  it("casa plantillas con parámetros", () => {
    const map: PublicPathMap = {
      "/products/[slug]": "/producto/[slug]",
      "/products/[slug]/learn": "/producto/[slug]/guia",
    };
    expect(toPublicPath("/products/vela/learn", "es", map)).toBe("/producto/vela/guia");
    expect(toPublicPath("/products/vela", "es", map)).toBe("/producto/vela");
  });

  it("no toca rutas que no están en el mapa ni URLs absolutas", () => {
    expect(toPublicPath("/about", "es", MAP)).toBe("/about");
    expect(toPublicPath("https://otra.example/products/x", "es", MAP)).toBe(
      "https://otra.example/products/x",
    );
  });
});

describe("toInternalPath", () => {
  it("deshace la traducción en una ruta concreta", () => {
    expect(toInternalPath("/productos/vela-roja", "es", MAP)).toBe(
      "/products/vela-roja",
    );
    expect(toInternalPath("/cuenta/orders/12", "es", MAP)).toBe("/account/orders/12");
    expect(toInternalPath("/pago/return?ref=7", "es", MAP)).toBe(
      "/checkout/return?ref=7",
    );
  });

  it("sin idioma acepta la forma pública de cualquiera de los idiomas", () => {
    expect(toInternalPath("/categorias/velas", undefined, MAP)).toBe(
      "/categories/velas",
    );
    expect(toInternalPath("/categories/velas", undefined, MAP)).toBe(
      "/categories/velas",
    );
  });

  it("una ruta interna se queda como está", () => {
    expect(toInternalPath("/products/vela", "es", MAP)).toBe("/products/vela");
  });
});

describe("aliases y tramos de la raíz", () => {
  it("devuelve los primeros segmentos públicos de una vista", () => {
    expect(publicAliases("products", MAP)).toEqual(["productos"]);
    expect(publicAliases("categories", MAP)).toEqual(["categorias", "categories"]);
    expect(publicAliases("about", MAP)).toEqual([]);
  });

  it("lista los tramos públicos que ocupa el mapa", () => {
    expect(publicRootSegments(MAP)).toEqual(
      expect.arrayContaining([
        "productos",
        "categorias",
        "categories",
        "carrito",
        "archivo",
      ]),
    );
  });
});

describe("publicPathEntries", () => {
  it("normaliza las claves y falla ante una entrada inválida", () => {
    expect(publicPathEntries({ "/products": "/productos" })[0]).toMatchObject({
      internal: "/products",
      internalSegments: ["products"],
    });
    expect(() => publicPathEntries({ products: "/productos" })).toThrow(/empezar por/);
    expect(() => publicPathEntries({ "/products/": "/productos" })).toThrow(
      /acabar en/,
    );
    expect(() => publicPathEntries({ "/products": "" })).toThrow(/empezar por/);
    expect(() => publicPathEntries({ "/products": 3 as never })).toThrow(
      /mapa por idioma/,
    );
  });
});

describe("validatePublicPaths", () => {
  const options = {
    locales: ["es", "en"],
    defaultLocale: "es",
    internalRoots: CORE_ROOT_SEGMENTS,
  };

  it("acepta un mapa correcto con uno y con dos idiomas", () => {
    expect(() => validatePublicPaths(MAP, options)).not.toThrow();
    const single: PublicPathMap = {
      "/products": "/productos",
      "/categories": "/categorias",
      "/cart": "/carrito",
    };
    expect(() =>
      validatePublicPaths(single, {
        locales: ["es"],
        defaultLocale: "es",
        internalRoots: CORE_ROOT_SEGMENTS,
      }),
    ).not.toThrow();
  });

  it("rechaza dos rutas que acaban en la misma URL", () => {
    expect(() =>
      validatePublicPaths({ "/cart": "/carrito", "/checkout": "/carrito" }, options),
    ).toThrow(/misma URL/);
  });

  it("rechaza una ruta pública que es la interna de otra vista", () => {
    expect(() => validatePublicPaths({ "/cart": "/checkout" }, options)).toThrow(
      /ruta interna de otra vista/,
    );
  });

  it("no deja ocupar un idioma ni la API", () => {
    expect(() => validatePublicPaths({ "/about": { es: "/en" } }, options)).toThrow(
      /es un idioma/,
    );
    expect(() => validatePublicPaths({ "/about": "/api" }, options)).toThrow(/BFF/);
  });

  it("rechaza un idioma que no está en routing.locales", () => {
    expect(() =>
      validatePublicPaths({ "/about": { fr: "/a-propos" } }, options),
    ).toThrow(/no está en routing.locales/);
  });

  it("rechaza la home como ruta pública", () => {
    expect(() => validatePublicPaths({ "/about": "/" }, options)).toThrow(/home/);
  });
});
