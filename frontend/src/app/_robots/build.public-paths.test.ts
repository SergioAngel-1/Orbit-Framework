import { beforeEach, describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { SiteConfig } from "@/lib/config/types";

// ============================================================================
//  robots.txt con rutas privadas remapeadas: veta la forma PÚBLICA en cada idioma y
//  también la interna, que sigue accesible con un 308 hacia la pública.
// ============================================================================

vi.mock("@/instance/registry", () => ({
  instanceRegistry: {
    revalidationTags: [],
    publicPaths: {
      "/account": "/cuenta",
      "/cart": "/carrito",
      "/checkout": "/pago",
      "/login": "/entrar",
    },
  },
}));

let currentConfig: SiteConfig;

vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => currentConfig,
  isFallbackSiteConfig: () => false,
}));

import { robotsResponse } from "./build";

async function robots(): Promise<string> {
  vi.stubEnv("NODE_ENV", "production");
  return (await robotsResponse()).text();
}

beforeEach(() => {
  currentConfig = structuredClone(CONFIG_DEFAULTS);
  currentConfig.brand.url = "https://mitienda.example/";
});

describe("robots.txt con rutas públicas", () => {
  it("veta la ruta pública en cada idioma", async () => {
    const body = await robots();
    expect(body).toContain("Disallow: /cuenta");
    expect(body).toContain("Disallow: /en/cuenta");
    expect(body).toContain("Disallow: /carrito");
    expect(body).toContain("Disallow: /pago");
    expect(body).toContain("Disallow: /entrar");
  });

  it("sigue vetando la ruta interna, que redirige a la pública", async () => {
    const body = await robots();
    expect(body).toContain("Disallow: /account");
    expect(body).toContain("Disallow: /en/account");
    expect(body).toContain("Disallow: /checkout");
  });
});
