import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en", "pt-BR"], defaultLocale: "es" },
}));

const resolveBySlugParam = vi.fn();
vi.mock("@/lib/i18n/slug-resolution", () => ({
  resolveBySlugParam: (...args: unknown[]) => resolveBySlugParam(...args),
}));

import { resolveEntityRoute } from "./entity-route";

const load = vi.fn();

beforeEach(() => {
  resolveBySlugParam.mockReset();
  load.mockReset();
});

describe("resolveEntityRoute", () => {
  it("el tramo ya canónico no pide redirección", async () => {
    resolveBySlugParam.mockResolvedValue({
      item: { urlSlug: "t-shirt" },
      nativeSlug: "camiseta",
    });

    const route = await resolveEntityRoute(
      "product",
      "/products",
      "t-shirt",
      "en",
      load,
    );

    expect(route).toMatchObject({
      nativeSlug: "camiseta",
      canonicalSlug: "t-shirt",
      canonicalHref: "/products/t-shirt",
      isCanonical: true,
    });
  });

  it("el slug nativo (u otro idioma) pedido en /en apunta al slug del idioma", async () => {
    resolveBySlugParam.mockResolvedValue({
      item: { urlSlug: "t-shirt" },
      nativeSlug: "camiseta",
    });

    const route = await resolveEntityRoute(
      "product",
      "/products",
      "camiseta",
      "en",
      load,
    );

    expect(route?.isCanonical).toBe(false);
    expect(route?.canonicalHref).toBe("/products/t-shirt");
  });

  it("sin urlSlug usa el nativo", async () => {
    resolveBySlugParam.mockResolvedValue({ item: {}, nativeSlug: "camiseta" });

    const route = await resolveEntityRoute("post", "/blog", "camiseta", "es", load);

    expect(route).toMatchObject({ canonicalSlug: "camiseta", isCanonical: true });
  });

  it("escapa el tramo en la ruta canónica", async () => {
    resolveBySlugParam.mockResolvedValue({
      item: { urlSlug: "a b" },
      nativeSlug: "a-b",
    });

    const route = await resolveEntityRoute("post", "/blog", "a-b", "es", load);

    expect(route?.canonicalHref).toBe("/blog/a%20b");
  });

  it("una ficha que no existe en ningún idioma es null y delega la búsqueda", async () => {
    resolveBySlugParam.mockResolvedValue(null);

    expect(
      await resolveEntityRoute("product_cat", "/categories", "x", "en", load),
    ).toBeNull();
    expect(resolveBySlugParam).toHaveBeenCalledWith("product_cat", "x", "en", load);
  });
});
