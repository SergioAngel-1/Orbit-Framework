import { beforeEach, describe, expect, it, vi } from "vitest";

const getProductsBySlugs = vi.fn();
vi.mock("@/lib/catalog/products", () => ({
  getProductsBySlugs: (...a: unknown[]) => getProductsBySlugs(...a),
}));
const getPostBySlug = vi.fn();
vi.mock("@/lib/blog/posts", () => ({
  getPostBySlug: (...a: unknown[]) => getPostBySlug(...a),
}));
const logger = vi.hoisted(() => ({ warn: vi.fn(), error: vi.fn(), info: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({ logger }));

import { resolveRecs } from "./resolve-recs";

const opts = { locale: "en", currentPostSlug: "esta-entrada" };

beforeEach(() => {
  getProductsBySlugs.mockReset();
  getPostBySlug.mockReset();
  logger.warn.mockReset();
});

describe("resolveRecs", () => {
  it("resuelve los productos en UNA consulta y en el idioma de la página", async () => {
    getProductsBySlugs.mockResolvedValue([{ slug: "camiseta", name: "T-shirt" }]);

    const out = await resolveRecs(
      [
        { type: "product", slug: "camiseta" },
        { type: "product", slug: "camiseta" },
      ],
      opts,
    );

    expect(getProductsBySlugs).toHaveBeenCalledTimes(1);
    expect(getProductsBySlugs).toHaveBeenCalledWith(["camiseta"], "en");
    expect(out.map((rec) => rec?.type)).toEqual(["product", "product"]);
  });

  it("la entrada actual no se recomienda a sí misma", async () => {
    const out = await resolveRecs([{ type: "post", slug: "esta-entrada" }], opts);

    expect(out).toEqual([null]);
    expect(getPostBySlug).not.toHaveBeenCalled();
  });

  it("sin catálogo, el marcador de producto queda en null y se registra", async () => {
    getProductsBySlugs.mockRejectedValue(new Error("caído"));

    const out = await resolveRecs([{ type: "product", slug: "camiseta" }], opts);

    expect(out).toEqual([null]);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "blog.recs_products_failed" }),
      expect.any(String),
    );
  });

  it("una entrada recomendada que no se puede leer queda en null y se registra", async () => {
    getPostBySlug.mockRejectedValue(new Error("caído"));

    const out = await resolveRecs([{ type: "post", slug: "otra" }], opts);

    expect(out).toEqual([null]);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "blog.recs_post_failed", slug: "otra" }),
      expect.any(String),
    );
  });
});
