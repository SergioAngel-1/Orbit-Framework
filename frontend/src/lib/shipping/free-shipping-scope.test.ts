import { describe, it, expect } from "vitest";
import {
  EMPTY_FREE_SHIPPING_SCOPE,
  normalizeFreeShippingScope,
  summarizeCities,
} from "./free-shipping-scope";

const RAW = {
  categories: [
    {
      slug: "velas",
      name: "Velas",
      translations: {},
      children: [
        {
          slug: "velas-aromaticas",
          name: "Velas aromáticas",
          translations: {
            en: { name: "Scented candles", slug: "scented-candles" },
            pt_br: { name: "Velas aromáticas BR", slug: "" },
          },
        },
        { slug: "", name: "Sin slug" },
        "basura",
      ],
    },
    { name: "Sin slug" },
  ],
  products: [
    {
      slug: "vela-roja",
      name: "Vela roja",
      translations: { en: { name: "Red candle", slug: "" } },
    },
    { slug: "jabon", name: "Jabón", translations: {} },
    { slug: "x", name: "" },
  ],
  cities: ["Madrid", " Sevilla ", "", 7],
};

describe("normalizeFreeShippingScope", () => {
  it("en el idioma por defecto usa nombre y slug nativos y descarta lo inválido", () => {
    expect(normalizeFreeShippingScope(RAW, "es")).toEqual({
      categories: [
        {
          name: "Velas",
          path: "/categories/velas",
          children: [
            { name: "Velas aromáticas", path: "/categories/velas-aromaticas" },
          ],
        },
      ],
      products: ["Vela roja", "Jabón"],
      cities: ["Madrid", "Sevilla"],
    });
  });

  it("en un idioma secundario traduce campo a campo y cae al nativo donde falta", () => {
    expect(normalizeFreeShippingScope(RAW, "en")).toEqual({
      categories: [
        {
          name: "Velas",
          path: "/categories/velas",
          children: [{ name: "Scented candles", path: "/categories/scented-candles" }],
        },
      ],
      products: ["Red candle", "Jabón"],
      cities: ["Madrid", "Sevilla"],
    });
  });

  it("sirve para cualquier idioma del sitio, también con región (pt-BR → pt_br)", () => {
    const scope = normalizeFreeShippingScope(RAW, "pt-BR");

    expect(scope.categories[0].children[0]).toEqual({
      name: "Velas aromáticas BR",
      // Traducción a medias: el nombre es del idioma, el slug cae al nativo.
      path: "/categories/velas-aromaticas",
    });
  });

  it("un idioma sin traducciones usa los textos nativos", () => {
    expect(normalizeFreeShippingScope(RAW, "fr").products).toEqual([
      "Vela roja",
      "Jabón",
    ]);
  });

  it("devuelve el alcance vacío ante un payload que no es un objeto", () => {
    expect(normalizeFreeShippingScope(null, "es")).toEqual(EMPTY_FREE_SHIPPING_SCOPE);
    expect(normalizeFreeShippingScope([], "es")).toEqual(EMPTY_FREE_SHIPPING_SCOPE);
    expect(normalizeFreeShippingScope({ categories: "x" }, "es")).toEqual(
      EMPTY_FREE_SHIPPING_SCOPE,
    );
  });

  it("tolera un mapa de traducciones que no es un objeto", () => {
    const scope = normalizeFreeShippingScope(
      {
        products: [{ slug: "a", name: "A", translations: "roto" }],
        categories: [],
        cities: [],
      },
      "en",
    );

    expect(scope.products).toEqual(["A"]);
  });
});

describe("summarizeCities", () => {
  const six = ["Barcelona", "Bilbao", "Madrid", "Sevilla", "Valencia", "Vigo"];

  it("nombra todas las que caben", () => {
    expect(summarizeCities(six.slice(0, 4))).toEqual({
      shown: six.slice(0, 4),
      hidden: 0,
    });
  });

  it("con más, nombra las primeras y cuenta el resto", () => {
    expect(summarizeCities(six)).toEqual({ shown: six.slice(0, 3), hidden: 3 });
    // Nunca «y 1 más»: con una de sobra se esconden dos.
    expect(summarizeCities(six.slice(0, 5)).hidden).toBe(2);
  });

  it("desplegada las nombra todas", () => {
    expect(summarizeCities(six, true)).toEqual({ shown: six, hidden: 0 });
  });
});
