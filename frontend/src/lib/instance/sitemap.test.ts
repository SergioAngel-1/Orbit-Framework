import { beforeEach, describe, expect, it, vi } from "vitest";

// El registro real no se toca: el mapa se inyecta en cada llamada (como en
// `public-paths.ts`) y el evento se comprueba con el logger simulado.
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));

import { logger } from "@/lib/observability/logger";
import { isSitemapPathExcluded, resolveInstanceSitemap } from "./sitemap";

beforeEach(() => {
  vi.clearAllMocks();
});

describe("resolveInstanceSitemap", () => {
  it("sin el campo no hay páginas ni exclusiones", () => {
    expect(resolveInstanceSitemap(undefined)).toEqual({
      pages: [],
      exact: [],
      prefixes: [],
    });
    expect(resolveInstanceSitemap({})).toEqual({ pages: [], exact: [], prefixes: [] });
  });

  it("normaliza una página con los valores por defecto", () => {
    const rules = resolveInstanceSitemap({ pages: [{ path: "/example-page" }] });

    expect(rules.pages).toEqual([
      { path: "/example-page", changeFrequency: "monthly", priority: 0.5 },
    ]);
  });

  it("conserva la frecuencia y la prioridad declaradas, y recorta el path", () => {
    const rules = resolveInstanceSitemap({
      pages: [{ path: " /guide ", changeFrequency: "weekly", priority: 0.7 }],
    });

    expect(rules.pages).toEqual([
      { path: "/guide", changeFrequency: "weekly", priority: 0.7 },
    ]);
  });

  it.each([
    ["sin barra inicial", { path: "guide" }],
    ["con consulta", { path: "/guide?x=1" }],
    ["con ancla", { path: "/guide#top" }],
    ["con barra final", { path: "/guide/" }],
    ["la home", { path: "/" }],
    ["con doble barra", { path: "/guide//x" }],
    ["dinámica (no se puede expandir)", { path: "/guide/[slug]" }],
    ["prioridad fuera de rango", { path: "/guide", priority: 1.5 }],
    ["prioridad negativa", { path: "/guide", priority: -0.1 }],
    ["frecuencia desconocida", { path: "/guide", changeFrequency: "sometimes" }],
    ["sin path", { changeFrequency: "weekly" }],
    ["path que no es texto", { path: 42 }],
  ])("descarta una entrada %s y lo registra", (_label, entry) => {
    const rules = resolveInstanceSitemap({
      pages: [entry as never, { path: "/ok" }],
    });

    expect(rules.pages.map((page) => page.path)).toEqual(["/ok"]);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "instance.sitemap.invalid_entry" }),
      expect.any(String),
    );
  });

  it("separa exclusiones exactas y prefijos (`/*`)", () => {
    const rules = resolveInstanceSitemap({
      exclude: ["/legal/privacy", "/products/*", "/about"],
    });

    expect(rules.exact).toEqual(["/legal/privacy", "/about"]);
    expect(rules.prefixes).toEqual(["/products"]);
  });

  it("una exclusión mal formada se descarta y lo registra", () => {
    const rules = resolveInstanceSitemap({
      exclude: ["legal", "/guide?x", "/products/*/x", 7 as never, "/about"],
    });

    expect(rules.exact).toEqual(["/about"]);
    expect(rules.prefixes).toEqual([]);
    expect(logger.warn).toHaveBeenCalled();
  });
});

describe("isSitemapPathExcluded", () => {
  const rules = resolveInstanceSitemap({
    exclude: ["/legal/privacy", "/products/*"],
  });

  it("la exclusión exacta solo casa la ruta exacta", () => {
    expect(isSitemapPathExcluded("/legal/privacy", rules)).toBe(true);
    expect(isSitemapPathExcluded("/legal/privacy/sub", rules)).toBe(false);
  });

  it("el prefijo casa la base y sus descendientes, no un texto que solo empieza igual", () => {
    expect(isSitemapPathExcluded("/products", rules)).toBe(true);
    expect(isSitemapPathExcluded("/products/camiseta", rules)).toBe(true);
    expect(isSitemapPathExcluded("/products/camiseta/learn", rules)).toBe(true);
    expect(isSitemapPathExcluded("/products-extra", rules)).toBe(false);
  });
});
