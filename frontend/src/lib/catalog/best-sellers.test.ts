import { afterEach, describe, expect, it, vi } from "vitest";

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...a: unknown[]) => fetchGraphQL(...a),
}));
vi.mock("@/lib/memberships/catalog-flag", () => ({
  membershipsCatalogEnabled: vi.fn(async () => false),
}));

import { getBestSellingProducts, parseBestSellerRanking } from "./best-sellers";

const node = (databaseId: number) => ({
  id: `p${databaseId}`,
  databaseId,
  name: `P${databaseId}`,
  slug: `p${databaseId}`,
  type: "SIMPLE",
  image: null,
});

afterEach(() => {
  vi.unstubAllGlobals();
  fetchGraphQL.mockReset();
});

describe("parseBestSellerRanking", () => {
  it("descarta filas inválidas y duplicadas, y conserva el orden", () => {
    expect(
      parseBestSellerRanking({
        items: [
          { id: 3, slug: "c", qty: 10 },
          { id: 0, slug: "x", qty: 5 },
          { id: 4, slug: "", qty: 5 },
          { id: 5, slug: "cero", qty: 0 },
          { id: 3, slug: "c", qty: 1 },
          { id: 1, slug: "a", qty: 2 },
        ],
      }),
    ).toEqual([
      { id: 3, slug: "c", qty: 10 },
      { id: 1, slug: "a", qty: 2 },
    ]);
  });

  it("cualquier otra forma da un ranking vacío", () => {
    expect(parseBestSellerRanking(null)).toEqual([]);
    expect(parseBestSellerRanking({ items: "no" })).toEqual([]);
  });
});

describe("getBestSellingProducts", () => {
  it("pide los primeros del ranking y respeta su orden", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        Response.json({
          items: [
            { id: 7, slug: "b", qty: 9 },
            { id: 2, slug: "a", qty: 4 },
            { id: 9, slug: "c", qty: 1 },
          ],
        }),
      ),
    );
    // WooGraphQL no garantiza el orden del `include`.
    fetchGraphQL.mockResolvedValue({ products: { nodes: [node(2), node(7)] } });

    const products = await getBestSellingProducts(2);

    expect(fetchGraphQL.mock.calls[0][1].variables.ids).toEqual([7, 2]);
    expect(products.map((p) => p.slug)).toEqual(["p7", "p2"]);
  });

  it("sin ranking (WordPress caído) devuelve vacío sin consultar el catálogo", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response("x", { status: 503 })),
    );

    expect(await getBestSellingProducts()).toEqual([]);
    expect(fetchGraphQL).not.toHaveBeenCalled();
  });
});
