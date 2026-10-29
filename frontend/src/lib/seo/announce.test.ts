import { describe, expect, it, vi } from "vitest";

vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/instance/registry", () => ({ instanceRegistry: {} }));

import { resolveInstanceSitemap } from "@/lib/instance/sitemap";
import { isPathAnnounced } from "./announce";

const rules = resolveInstanceSitemap({
  exclude: ["/products/exacta", "/categories/*"],
});

describe("isPathAnnounced", () => {
  it("sin caja SEO ni exclusiones, se anuncia", () => {
    expect(isPathAnnounced("/products/camiseta", {}, rules)).toBe(true);
    expect(isPathAnnounced("/products/camiseta")).toBe(true);
  });

  it("la caja SEO la retira: noindex o canónica ajena", () => {
    expect(isPathAnnounced("/products/a", { seoNoindex: true }, rules)).toBe(false);
    expect(
      isPathAnnounced("/products/a", { seoCanonical: "https://otra.example/a" }, rules),
    ).toBe(false);
  });

  it("una exclusión exacta de la instancia la retira, y solo a esa ruta", () => {
    expect(isPathAnnounced("/products/exacta", {}, rules)).toBe(false);
    expect(isPathAnnounced("/products/exacta-2", {}, rules)).toBe(true);
  });

  it("un prefijo con `/*` retira todo lo que cuelga de él", () => {
    expect(isPathAnnounced("/categories/ropa", {}, rules)).toBe(false);
    expect(isPathAnnounced("/categories", {}, rules)).toBe(false);
    expect(isPathAnnounced("/categories-extra/x", {}, rules)).toBe(true);
  });
});
