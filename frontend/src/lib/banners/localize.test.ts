import { beforeEach, describe, expect, it, vi } from "vitest";
import { getCategories, getProductsBySlugs } from "@/lib/catalog/products";
import { logReadFailure } from "@/lib/observability/read-failure";
import type { BannerPlacement, BannerSlide } from "./types";

// Una instancia que sirve el catálogo en español: los enlaces del CMS pueden venir en la
// forma interna o en la pública.
vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: { "/products": "/productos", "/categories": "/categorias" },
  },
}));
import {
  catalogSlugs,
  localizePlacement,
  localizeSlideHrefs,
  parseCatalogHref,
} from "./localize";

vi.mock("@/lib/catalog/products", () => ({
  getCategories: vi.fn(),
  getProductsBySlugs: vi.fn(),
}));
vi.mock("@/lib/observability/read-failure", () => ({ logReadFailure: vi.fn() }));

const slide = (over: Partial<BannerSlide> = {}): BannerSlide => ({
  id: 1,
  image: "a.jpg",
  title: "T",
  ...over,
});
const placement = (slides: BannerSlide[]): BannerPlacement => ({
  slides,
  intervalMs: 6000,
});

beforeEach(() => {
  vi.mocked(getCategories).mockReset();
  vi.mocked(getProductsBySlugs).mockReset();
  vi.mocked(logReadFailure).mockReset();
});

describe("parseCatalogHref", () => {
  it.each([
    ["/products/vela-roja", { kind: "products", slug: "vela-roja", rest: "" }],
    [
      "/categories/velas?sort=price",
      { kind: "categories", slug: "velas", rest: "?sort=price" },
    ],
    [
      "/products/vela%20roja#reseñas",
      { kind: "products", slug: "vela roja", rest: "#reseñas" },
    ],
    ["/productos/vela-roja", { kind: "products", slug: "vela-roja", rest: "" }],
    [
      "/categorias/velas?sort=price",
      { kind: "categories", slug: "velas", rest: "?sort=price" },
    ],
  ])("%s", (href, expected) => {
    expect(parseCatalogHref(href)).toEqual(expected);
  });

  it.each([
    "/blog/mi-entrada",
    "https://otro.example/products/x",
    "#ancla",
    "",
    "/products/",
  ])("no es del catálogo: «%s»", (href) => {
    expect(parseCatalogHref(href)).toBeNull();
  });
});

describe("localizeSlideHrefs", () => {
  const resolve = {
    product: (s: string) => ({ "vela-roja": "red-candle" })[s],
    category: (s: string) => ({ velas: "candles" })[s],
  };

  it("lleva cada enlace del catálogo al slug de su idioma, en los tres campos de enlace", () => {
    const [out] = localizeSlideHrefs(
      [
        slide({
          ctaHref: "/products/vela-roja",
          cta2Href: "/categories/velas",
          link: "/products/vela-roja?x=1",
        }),
      ],
      resolve,
    );

    expect(out.ctaHref).toBe("/products/red-candle");
    expect(out.cta2Href).toBe("/categories/candles");
    expect(out.link).toBe("/products/red-candle?x=1");
  });

  it("deja como están los enlaces que no resuelve o que no son del catálogo", () => {
    const [out] = localizeSlideHrefs(
      [
        slide({
          ctaHref: "/products/otro",
          cta2Href: "/blog/entrada",
          link: "https://x.example",
        }),
      ],
      resolve,
    );

    expect([out.ctaHref, out.cta2Href, out.link]).toEqual([
      "/products/otro",
      "/blog/entrada",
      "https://x.example",
    ]);
  });

  it("no muta los slides de entrada", () => {
    const input = slide({ ctaHref: "/products/vela-roja" });
    localizeSlideHrefs([input], resolve);
    expect(input.ctaHref).toBe("/products/vela-roja");
  });
});

describe("catalogSlugs", () => {
  it("reúne los slugs únicos de producto y de categoría", () => {
    expect(
      catalogSlugs([
        slide({ ctaHref: "/products/a", link: "/categories/c" }),
        slide({ ctaHref: "/products/a", cta2Href: "/products/b" }),
      ]),
    ).toEqual({ products: ["a", "b"], categories: ["c"] });
  });
});

describe("localizePlacement", () => {
  it("en el idioma por defecto no consulta nada", async () => {
    const p = placement([slide({ ctaHref: "/products/vela-roja" })]);

    expect(await localizePlacement(p, "es")).toBe(p);
    expect(getProductsBySlugs).not.toHaveBeenCalled();
  });

  it("sin enlaces al catálogo no consulta nada, en ningún idioma", async () => {
    const p = placement([slide({ ctaHref: "/blog/x" })]);

    expect(await localizePlacement(p, "en")).toBe(p);
    expect(getCategories).not.toHaveBeenCalled();
  });

  it("en un idioma secundario resuelve productos y categorías con el catálogo", async () => {
    vi.mocked(getProductsBySlugs).mockResolvedValue([
      { slug: "vela-roja", urlSlug: "red-candle" },
    ] as never);
    vi.mocked(getCategories).mockResolvedValue([
      { slug: "velas", urlSlug: "candles" },
    ] as never);

    const out = await localizePlacement(
      placement([slide({ ctaHref: "/products/vela-roja", link: "/categories/velas" })]),
      "en",
    );

    expect(getProductsBySlugs).toHaveBeenCalledWith(["vela-roja"], "en");
    expect(getCategories).toHaveBeenCalledWith("en");
    expect(out.slides[0]).toMatchObject({
      ctaHref: "/products/red-candle",
      link: "/categories/candles",
    });
  });

  it("solo pide lo que hace falta: sin categorías no lista categorías", async () => {
    vi.mocked(getProductsBySlugs).mockResolvedValue([]);

    await localizePlacement(placement([slide({ ctaHref: "/products/a" })]), "en");

    expect(getCategories).not.toHaveBeenCalled();
  });

  it("funciona con cualquier idioma del sitio, no solo el inglés", async () => {
    vi.mocked(getProductsBySlugs).mockResolvedValue([
      { slug: "vela-roja", urlSlug: "vela-vermelha" },
    ] as never);

    const out = await localizePlacement(
      placement([slide({ ctaHref: "/products/vela-roja" })]),
      "pt-BR",
    );

    expect(getProductsBySlugs).toHaveBeenCalledWith(["vela-roja"], "pt-BR");
    expect(out.slides[0].ctaHref).toBe("/products/vela-vermelha");
  });

  it("si el catálogo falla, deja los enlaces como estaban y lo registra", async () => {
    vi.mocked(getProductsBySlugs).mockRejectedValue(new Error("caído"));
    const p = placement([slide({ ctaHref: "/products/vela-roja" })]);

    expect(await localizePlacement(p, "en")).toBe(p);
    expect(logReadFailure).toHaveBeenCalledWith(
      "banners.localize_failed",
      expect.any(Error),
      expect.objectContaining({ level: "warn" }),
    );
  });
});
