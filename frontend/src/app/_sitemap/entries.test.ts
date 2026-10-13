import { beforeEach, describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { SiteConfig } from "@/lib/config/types";

let currentConfig: SiteConfig;
let degraded = false;

vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => currentConfig,
  isFallbackSiteConfig: () => degraded,
}));
vi.mock("@/lib/catalog/products", () => ({
  getProductSlugs: async () => ["camiseta", "gorra"],
  getCategories: async () => [{ slug: "ropa" }],
}));
vi.mock("@/lib/blog/posts", () => ({
  getPostSlugs: async () => [
    { slug: "con-fecha", modified: "2026-09-20T10:00:00" },
    { slug: "sin-fecha", modified: null },
  ],
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn() },
}));

import { SITEMAP_SEGMENTS } from "@/lib/seo/sitemap-segments";
import { buildSitemapEntries } from "./entries";

beforeEach(() => {
  degraded = false;
  currentConfig = structuredClone(CONFIG_DEFAULTS);
  currentConfig.brand.url = "https://mitienda.example/";
});

describe("buildSitemapEntries", () => {
  it("el segmento de páginas lleva cada idioma y sus alternates con x-default", async () => {
    const entries = await buildSitemapEntries("paginas");
    const products = entries.filter((e) => e.url.endsWith("/products"));
    expect(products.map((e) => e.url)).toEqual([
      "https://mitienda.example/products",
      "https://mitienda.example/en/products",
    ]);
    expect(products[0]!.alternates!.languages).toEqual({
      es: "https://mitienda.example/products",
      en: "https://mitienda.example/en/products",
      "x-default": "https://mitienda.example/products",
    });
    // La home de un idioma con prefijo es /en, no /en/.
    expect(entries.map((e) => e.url)).toContain("https://mitienda.example/en");
  });

  it("el catálogo lista fichas y categorías, sin lastmod inventado", async () => {
    const entries = await buildSitemapEntries("catalogo");
    const urls = entries.map((e) => e.url);
    expect(urls).toContain("https://mitienda.example/products/camiseta");
    expect(urls).toContain("https://mitienda.example/en/categories/ropa");
    for (const entry of entries) expect(entry).not.toHaveProperty("lastModified");
  });

  it("el blog solo emite lastmod cuando hay fecha real", async () => {
    const entries = await buildSitemapEntries("blog");
    const dated = entries.find((e) => e.url.endsWith("/blog/con-fecha"))!;
    const undated = entries.find((e) => e.url.endsWith("/blog/sin-fecha"))!;
    expect(dated.lastModified).toBeInstanceOf(Date);
    expect(undated).not.toHaveProperty("lastModified");
  });

  it("con la config degradada emite un urlset vacío, no URLs con el dominio por defecto", async () => {
    degraded = true;
    for (const segment of SITEMAP_SEGMENTS) {
      expect(await buildSitemapEntries(segment)).toEqual([]);
    }
  });

  it("no anuncia vistas privadas ni derivadas", async () => {
    const all = (
      await Promise.all(SITEMAP_SEGMENTS.map((s) => buildSitemapEntries(s)))
    ).flat();
    for (const { url } of all) {
      expect(url).not.toMatch(/\/(account|cart|checkout|login|register)(\/|$)/);
      expect(url).not.toContain("?");
    }
  });
});
