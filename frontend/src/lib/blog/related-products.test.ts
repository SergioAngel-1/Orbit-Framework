import { describe, expect, it } from "vitest";
import {
  buildMentionedProductsStrip,
  MENTIONED_PRODUCTS_MAX,
} from "./related-products";

const p = (slug: string, categorySlugs: string[] = [], canonicalCategory?: string) => ({
  slug,
  categorySlugs,
  canonicalCategory,
});

describe("buildMentionedProductsStrip", () => {
  it("sin menciones no hay franja", () => {
    expect(buildMentionedProductsStrip([], [p("a", ["x"])])).toEqual({
      items: [],
      hasRelated: false,
    });
  });

  it("completa hasta el máximo con productos parecidos, los más cercanos primero", () => {
    const mentioned = [p("m", ["ropa", "verano"], "camisetas")];
    const catalog = [
      p("m", ["ropa", "verano"], "camisetas"),
      p("lejano", ["hogar"]),
      p("misma-linea", ["ropa"]),
      p("misma-canonica", ["otra"], "camisetas"),
    ];
    const strip = buildMentionedProductsStrip(mentioned, catalog);
    expect(strip.items.map((i) => i.slug)).toEqual([
      "m",
      "misma-canonica",
      "misma-linea",
    ]);
    expect(strip.hasRelated).toBe(true);
  });

  it("respeta el máximo y el orden de lo mencionado", () => {
    const mentioned = Array.from({ length: 6 }, (_, i) => p(`m${i}`, ["x"]));
    const strip = buildMentionedProductsStrip(mentioned, mentioned);
    expect(strip.items).toHaveLength(MENTIONED_PRODUCTS_MAX);
    expect(strip.items.map((i) => i.slug)).toEqual(["m0", "m1", "m2", "m3"]);
    expect(strip.hasRelated).toBe(false);
  });

  it("nunca repite un producto ya mencionado", () => {
    const m = p("m", ["x"]);
    const strip = buildMentionedProductsStrip([m], [m, m]);
    expect(strip.items.map((i) => i.slug)).toEqual(["m"]);
    expect(strip.hasRelated).toBe(false);
  });
});
