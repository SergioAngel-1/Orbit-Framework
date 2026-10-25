import { beforeEach, describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { SiteConfig } from "@/lib/config/types";

// ============================================================================
//  Canónicas, hreflang, JSON-LD y sitemap con las rutas PÚBLICAS de la instancia.
//  El mapa remapea el catálogo y la cuenta (con su hijo heredado).
// ============================================================================

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: {
      "/products": "/productos",
      "/categories": "/categorias",
      "/account": "/cuenta",
      "/blog": "/novedades",
    },
  },
}));

let currentConfig: SiteConfig;

vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => currentConfig,
  isFallbackSiteConfig: () => false,
}));
vi.mock("@/lib/landings/product-bound-landings", () => ({
  getProductBoundLandingSlugs: async () => new Set<string>(),
  getProductBoundLandingLocales: async () => ({}),
}));
vi.mock("@/lib/catalog/products", () => ({
  getProductUrls: async () => [],
  getCategories: async () => [],
}));
vi.mock("@/lib/blog/posts", () => ({ getPostSlugs: async () => [] }));
vi.mock("@/lib/blog/categories", () => ({ getBlogCategories: async () => [] }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn() },
}));

import {
  absoluteLocalized,
  alternatesFor,
  hreflangLanguages,
  localizedPath,
} from "./urls";
import { buildSitemapEntries } from "@/app/_sitemap/entries";

beforeEach(() => {
  currentConfig = structuredClone(CONFIG_DEFAULTS);
  currentConfig.brand.url = "https://mitienda.example/";
});

describe("localizedPath con rutas públicas", () => {
  it("traduce el tramo y conserva el prefijo de idioma", () => {
    expect(localizedPath("/products/vela", "es")).toBe("/productos/vela");
    expect(localizedPath("/products/vela", "en")).toBe("/en/productos/vela");
    expect(localizedPath("/", "en")).toBe("/en");
  });

  it("una ruta no remapeada se queda como estaba", () => {
    expect(localizedPath("/about", "es")).toBe("/about");
    expect(localizedPath("/about", "en")).toBe("/en/about");
  });
});

describe("canónicas y hreflang", () => {
  it("la canónica y el x-default usan el tramo público", () => {
    expect(alternatesFor("/products", "es")).toEqual({
      canonical: "/productos",
      languages: {
        es: "/productos",
        en: "/en/productos",
        "x-default": "/productos",
      },
    });
  });

  it("hereda el prefijo de un padre remapeado", () => {
    expect(hreflangLanguages("/account/orders")).toEqual({
      es: "/cuenta/orders",
      en: "/en/cuenta/orders",
      "x-default": "/cuenta/orders",
    });
  });

  it("el JSON-LD sale con la URL pública absoluta", () => {
    expect(absoluteLocalized("https://mitienda.example/", "/products/x", "es")).toBe(
      "https://mitienda.example/productos/x",
    );
  });
});

describe("sitemap", () => {
  it("las URL y sus alternates salen con el tramo público", async () => {
    const entries = await buildSitemapEntries("paginas");
    const products = entries.find((entry) => entry.url.endsWith("/productos"));

    expect(products).toBeDefined();
    expect(products!.url).toBe("https://mitienda.example/productos");
    expect(products!.alternates!.languages).toEqual({
      es: "https://mitienda.example/productos",
      en: "https://mitienda.example/en/productos",
      "x-default": "https://mitienda.example/productos",
    });
  });
});
