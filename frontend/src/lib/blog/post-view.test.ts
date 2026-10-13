import { beforeEach, describe, expect, it, vi } from "vitest";

const getProductsBySlugs = vi.fn();
const getProducts = vi.fn();
vi.mock("@/lib/catalog/products", () => ({
  getProductsBySlugs: (...a: unknown[]) => getProductsBySlugs(...a),
  getProducts: (...a: unknown[]) => getProducts(...a),
}));
const getCartProductRefs = vi.fn();
vi.mock("@/lib/catalog/coupon-refs", () => ({
  getCartProductRefs: (...a: unknown[]) => getCartProductRefs(...a),
}));
const getPostBySlug = vi.fn();
vi.mock("@/lib/blog/posts", () => ({
  getPostBySlug: (...a: unknown[]) => getPostBySlug(...a),
}));

import { buildPostView } from "./post-view";

const product = (slug: string, databaseId: number) => ({
  id: String(databaseId),
  databaseId,
  name: slug,
  slug,
  type: "SIMPLE",
  onSale: false,
  price: null,
  regularPrice: null,
  salePrice: null,
  stockStatus: null,
  shortDescription: null,
  image: null,
});

const opts = {
  locale: "es",
  slug: "esta-entrada",
  siteOrigins: ["https://mitienda.example"],
};

beforeEach(() => {
  getProductsBySlugs.mockReset();
  getProducts.mockReset();
  getCartProductRefs.mockReset();
  getPostBySlug.mockReset();
  getProductsBySlugs.mockResolvedValue([]);
  getCartProductRefs.mockResolvedValue(new Map());
  getProducts.mockResolvedValue({ products: [], pageInfo: {} });
});

describe("buildPostView", () => {
  it("reescribe el enlace heredado de Woo a la ficha cuando el producto existe", async () => {
    getProductsBySlugs.mockResolvedValue([product("camiseta", 1)]);

    const view = await buildPostView(
      '<p><a href="https://antigua.example/producto/camiseta/?utm=x">c</a></p>',
      opts,
    );

    const html = view.segments.map((s) => (s.kind === "html" ? s.html : "")).join("");
    expect(html).toContain('href="/products/camiseta"');
    expect(view.mentioned.items.map((p) => p.slug)).toEqual(["camiseta"]);
  });

  it("deja el enlace como está si el producto ya no existe", async () => {
    const view = await buildPostView('<a href="/producto/fantasma/">x</a>', opts);
    const html = view.segments.map((s) => (s.kind === "html" ? s.html : "")).join("");
    expect(html).toContain('href="/producto/fantasma/"');
    expect(view.mentioned.items).toEqual([]);
  });

  it("completa la franja con productos de la misma categoría, sin repetir", async () => {
    getProductsBySlugs.mockResolvedValue([product("camiseta", 1)]);
    getCartProductRefs.mockResolvedValue(
      new Map([[1, { slug: "camiseta", categorySlugs: ["ropa"] }]]),
    );
    getProducts.mockResolvedValue({
      products: [product("camiseta", 1), product("gorra", 2), product("bufanda", 3)],
      pageInfo: {},
    });

    const view = await buildPostView('<a href="/products/camiseta">c</a>', opts);

    expect(view.mentioned.items.map((p) => p.slug)).toEqual([
      "camiseta",
      "gorra",
      "bufanda",
    ]);
    expect(view.mentioned.hasRelated).toBe(true);
    expect(getProducts).toHaveBeenCalledWith(
      expect.objectContaining({ category: "ropa" }),
    );
  });

  it("resuelve las recomendaciones y descarta la de la propia entrada", async () => {
    getProductsBySlugs.mockImplementation(async (slugs: string[]) =>
      slugs.includes("gorra") ? [product("gorra", 2)] : [],
    );
    getPostBySlug.mockResolvedValue({ id: "p", slug: "otra", title: "Otra" });

    const view = await buildPostView(
      [
        '<!-- hwe-rec type="product" slug="gorra" -->',
        '<!-- hwe-rec type="post" slug="otra" -->',
        '<!-- hwe-rec type="post" slug="esta-entrada" -->',
      ].join(""),
      opts,
    );

    expect(view.recs.map((r) => r?.type ?? null)).toEqual(["product", "post", null]);
    expect(getPostBySlug).toHaveBeenCalledTimes(1);
  });

  it("sin catálogo la entrada se pinta igual", async () => {
    getProductsBySlugs.mockRejectedValue(new Error("down"));
    getPostBySlug.mockRejectedValue(new Error("down"));

    const view = await buildPostView(
      '<h2>Título</h2><a href="/products/x">x</a><!-- hwe-rec type="post" slug="y" -->',
      opts,
    );

    expect(view.headings).toHaveLength(1);
    expect(view.recs).toEqual([null]);
    expect(view.mentioned.items).toEqual([]);
  });

  it("pide como máximo 24 productos mencionados", async () => {
    const links = Array.from(
      { length: 40 },
      (_, i) => `<a href="/products/p${i}">x</a>`,
    ).join("");
    await buildPostView(links, opts);
    expect(getProductsBySlugs.mock.calls[0]![0]).toHaveLength(24);
  });
});
