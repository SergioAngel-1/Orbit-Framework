import { beforeEach, describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { SiteConfig } from "@/lib/config/types";

// ============================================================================
//  llms.txt con las rutas públicas de la instancia (sale de `absoluteLocalized`).
// ============================================================================

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: {
      "/products": "/productos",
      "/blog": "/novedades",
      "/about": "/nosotros",
    },
  },
}));

let currentConfig: SiteConfig;

vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => currentConfig,
  isFallbackSiteConfig: () => false,
}));
vi.mock("@/lib/catalog/products", () => ({
  getCategories: async () => [],
}));
vi.mock("next-intl/server", () => ({
  getTranslations: async () => (key: string) => key,
}));

import { buildLlmsTxt } from "./build";

beforeEach(() => {
  currentConfig = structuredClone(CONFIG_DEFAULTS);
  currentConfig.brand.name = "Mi Tienda";
  currentConfig.brand.url = "https://mitienda.example/";
  currentConfig.geo.llms_txt_enabled = true;
});

describe("llms.txt con rutas públicas", () => {
  it("cada página principal sale con su tramo público", async () => {
    const body = (await buildLlmsTxt("es"))!;
    expect(body).toContain("(https://mitienda.example/productos)");
    expect(body).toContain("(https://mitienda.example/novedades)");
    expect(body).toContain("(https://mitienda.example/nosotros)");
    expect(body).not.toContain("(https://mitienda.example/products)");
  });

  it("en un idioma secundario conserva su prefijo", async () => {
    const body = (await buildLlmsTxt("en"))!;
    expect(body).toContain("(https://mitienda.example/en/productos)");
    expect(body).toContain("(https://mitienda.example/en/novedades)");
  });
});
