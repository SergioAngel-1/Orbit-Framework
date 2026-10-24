import { beforeEach, describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { SiteConfig } from "@/lib/config/types";

// Las URL que construye el núcleo no dependen del registro de la instancia: estos tests
// fijan el caso sin mapa público (los mapas se prueban en los `*.public-paths.test.ts`).
vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));

let currentConfig: SiteConfig;
let degraded = false;
/** Landings de producto publicadas y los idiomas de cada una (módulo `product-landing`). */
const learn = vi.hoisted(() => ({
  slugs: [] as string[],
  locales: {} as Record<string, string[]>,
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => currentConfig,
  isFallbackSiteConfig: () => degraded,
}));
vi.mock("@/lib/landings/product-bound-landings", () => ({
  getProductBoundLandingSlugs: async () => new Set(learn.slugs),
  getProductBoundLandingLocales: async () => learn.locales,
}));
vi.mock("@/lib/catalog/products", () => ({
  getProductUrls: async () => [
    {
      slug: "camiseta",
      slugs: { es: "camiseta", en: "t-shirt" },
      locales: ["es", "en"],
    },
    // Sin traducción: solo existe en el idioma por defecto.
    { slug: "gorra", slugs: { es: "gorra", en: "gorra" }, locales: ["es"] },
  ],
  getCategories: async () => [
    {
      slug: "ropa",
      urlSlugs: { es: "ropa", en: "clothes" },
      locales: ["es", "en"],
    },
  ],
}));
vi.mock("@/lib/blog/posts", () => ({
  getPostSlugs: async () => [
    {
      slug: "con-fecha",
      modified: "2026-09-20T10:00:00",
      slugs: { es: "con-fecha", en: "with-date" },
      locales: ["es", "en"],
    },
    {
      slug: "sin-fecha",
      modified: null,
      slugs: { es: "sin-fecha", en: "sin-fecha" },
      locales: ["es"],
    },
  ],
}));
vi.mock("@/lib/blog/categories", () => ({
  getBlogCategories: async () => [
    { name: "Guías", slug: "guias", description: null, count: 4 },
    { name: "Vacía", slug: "vacia", description: null, count: 0 },
    { name: "Sin categoría", slug: "uncategorized", description: null, count: 9 },
  ],
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn() },
}));

import { SITEMAP_SEGMENTS } from "@/lib/seo/sitemap-segments";
import { buildSitemapEntries } from "./entries";

beforeEach(() => {
  degraded = false;
  learn.slugs = [];
  learn.locales = {};
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
    expect(urls).toContain("https://mitienda.example/en/categories/clothes");
    for (const entry of entries) expect(entry).not.toHaveProperty("lastModified");
  });

  it("la ficha traducida usa el slug de cada idioma en la URL y en el hreflang", async () => {
    const entries = await buildSitemapEntries("catalogo");
    const es = entries.find(
      (e) => e.url === "https://mitienda.example/products/camiseta",
    )!;

    expect(es.alternates!.languages).toEqual({
      es: "https://mitienda.example/products/camiseta",
      en: "https://mitienda.example/en/products/t-shirt",
      "x-default": "https://mitienda.example/products/camiseta",
    });
    expect(entries.map((e) => e.url)).toContain(
      "https://mitienda.example/en/products/t-shirt",
    );
    // El slug nativo no sale bajo /en: ahí la URL es la traducida.
    expect(entries.map((e) => e.url)).not.toContain(
      "https://mitienda.example/en/products/camiseta",
    );
  });

  it("sin traducción no se anuncia el idioma: ni fila ni hreflang", async () => {
    const urls = (await buildSitemapEntries("catalogo")).map((e) => e.url);
    expect(urls).toContain("https://mitienda.example/products/gorra");
    expect(urls).not.toContain("https://mitienda.example/en/products/gorra");

    const blog = (await buildSitemapEntries("blog")).map((e) => e.url);
    expect(blog).toContain("https://mitienda.example/en/blog/with-date");
    expect(blog).not.toContain("https://mitienda.example/en/blog/sin-fecha");
  });

  describe("landings de producto (módulo product-landing)", () => {
    const catalog = async () =>
      (await buildSitemapEntries("catalogo")).map((entry) => entry.url);

    it("sin landings publicadas no hay filas /learn", async () => {
      expect((await catalog()).some((url) => url.includes("/learn"))).toBe(false);
    });

    it("una landing publicada y traducida se anuncia en cada idioma con su slug", async () => {
      learn.slugs = ["camiseta"];
      learn.locales = { camiseta: ["es", "en"] };

      const entries = await buildSitemapEntries("catalogo");
      const urls = entries.map((entry) => entry.url);
      const es = entries.find(
        (entry) => entry.url === "https://mitienda.example/products/camiseta/learn",
      )!;

      expect(urls).toContain("https://mitienda.example/en/products/t-shirt/learn");
      expect(es.alternates!.languages).toEqual({
        es: "https://mitienda.example/products/camiseta/learn",
        en: "https://mitienda.example/en/products/t-shirt/learn",
        "x-default": "https://mitienda.example/products/camiseta/learn",
      });
    });

    it("sin traducción de la landing no se anuncia el idioma", async () => {
      learn.slugs = ["camiseta"];
      learn.locales = { camiseta: ["es"] };

      const urls = await catalog();

      expect(urls).toContain("https://mitienda.example/products/camiseta/learn");
      expect(urls.some((url) => url.includes("/en/products/t-shirt/learn"))).toBe(
        false,
      );
    });

    it("sin idiomas verificados (lectura fallida) solo se anuncia el idioma por defecto", async () => {
      learn.slugs = ["camiseta"];
      learn.locales = {};

      const urls = await catalog();

      expect(urls).toContain("https://mitienda.example/products/camiseta/learn");
      expect(
        urls.some(
          (url) =>
            url.startsWith("https://mitienda.example/en/") && url.endsWith("/learn"),
        ),
      ).toBe(false);
    });

    it("el idioma debe existir también en la ficha", async () => {
      learn.slugs = ["gorra"];
      learn.locales = { gorra: ["es", "en"] };

      const urls = await catalog();

      expect(urls).toContain("https://mitienda.example/products/gorra/learn");
      expect(urls.some((url) => url.includes("/en/products/gorra/learn"))).toBe(false);
    });
  });

  describe("landings editoriales (módulo landings)", () => {
    const pages = async () =>
      (await buildSitemapEntries("paginas")).map((entry) => entry.url);

    beforeEach(() => {
      currentConfig.modules = { ...currentConfig.modules, landings: true };
      currentConfig.landings = {
        registry: "guia | Guía | article\nborrador | Borrador | article",
        editorial: {
          guia: {
            published: true,
            url: { base: "area", path: "guia-de-uso", path_en: "usage-guide" },
            seo: { title_en: "Usage guide", date_modified: "2026-03-01" },
          },
          borrador: { published: false },
        },
      };
    });

    it("sin el módulo no hay filas ni índice", async () => {
      currentConfig.modules = { ...currentConfig.modules, landings: false };

      expect((await pages()).some((url) => url.includes("/learn"))).toBe(false);
    });

    it("lista la landing publicada con el tramo de cada idioma y su alternates", async () => {
      const entries = await buildSitemapEntries("paginas");
      const es = entries.find(
        (e) => e.url === "https://mitienda.example/learn/guia-de-uso",
      )!;

      expect(entries.map((e) => e.url)).toContain(
        "https://mitienda.example/en/learn/usage-guide",
      );
      expect(entries.map((e) => e.url)).toContain("https://mitienda.example/learn");
      expect(es.alternates!.languages).toEqual({
        es: "https://mitienda.example/learn/guia-de-uso",
        en: "https://mitienda.example/en/learn/usage-guide",
        "x-default": "https://mitienda.example/learn/guia-de-uso",
      });
      // La fecha de revisión del editor es la única que se declara.
      expect(es.lastModified).toBeInstanceOf(Date);
    });

    it("sin traducción no anuncia el idioma y no lista las sin publicar", async () => {
      currentConfig.landings.editorial.guia = {
        published: true,
        url: { base: "area", path: "guia-de-uso" },
      };

      const urls = await pages();

      expect(urls).toContain("https://mitienda.example/learn/guia-de-uso");
      expect(urls.some((url) => url.includes("/en/learn/"))).toBe(false);
      expect(urls.some((url) => url.includes("borrador"))).toBe(false);
    });
  });

  it("el blog solo emite lastmod cuando hay fecha real", async () => {
    const entries = await buildSitemapEntries("blog");
    const dated = entries.find((e) => e.url.endsWith("/blog/con-fecha"))!;
    const undated = entries.find((e) => e.url.endsWith("/blog/sin-fecha"))!;
    expect(dated.lastModified).toBeInstanceOf(Date);
    expect(undated).not.toHaveProperty("lastModified");
  });

  it("el blog lleva el archivo y solo las categorías que su página sirve", async () => {
    const urls = (await buildSitemapEntries("blog")).map((e) => e.url);
    expect(urls).toContain("https://mitienda.example/blog/archive");
    expect(urls).toContain("https://mitienda.example/blog/category/guias");
    expect(urls.some((u) => u.includes("/category/vacia"))).toBe(false);
    expect(urls.some((u) => u.includes("/category/uncategorized"))).toBe(false);
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
