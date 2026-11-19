import { beforeEach, describe, expect, it, vi } from "vitest";

// ============================================================================
//  `getCategory` puede no traer los productos de la categoría.
//
//  La consulta resolvía siempre 24 tarjetas de producto con la categoría, y una
//  vista que pagina o filtra sus productos por su cuenta no las usaba: para
//  WordPress era la parte cara de la consulta. Con `products: 0` el campo se
//  omite (`@include(if: $withProducts)`); sin opción, todo queda como estaba.
// ============================================================================

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...args: unknown[]) => fetchGraphQL(...args),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/memberships/catalog-flag", () => ({
  membershipsCatalogEnabled: vi.fn(async () => false),
}));

import { getCategory } from "./products";

const CATEGORY = {
  id: "c1",
  databaseId: 9,
  name: "Ropa",
  slug: "ropa",
};
const node = {
  id: "p1",
  databaseId: 1,
  name: "Camiseta",
  slug: "camiseta",
  type: "SIMPLE",
};

/** Variables de la última consulta enviada. */
const lastVariables = () =>
  (fetchGraphQL.mock.calls.at(-1)?.[1] as { variables: Record<string, unknown> })
    .variables;

beforeEach(() => {
  fetchGraphQL.mockReset();
});

describe("getCategory: cuántos productos trae", () => {
  it("sin opción pide 24, como siempre, y los devuelve", async () => {
    fetchGraphQL.mockResolvedValue({
      productCategory: { ...CATEGORY, products: { nodes: [node] } },
    });

    const result = await getCategory("ropa");

    expect(lastVariables()).toEqual({ slug: "ropa", first: 24 });
    expect(result!.products).toHaveLength(1);
  });

  it("con un número, pide ese", async () => {
    fetchGraphQL.mockResolvedValue({
      productCategory: { ...CATEGORY, products: { nodes: [] } },
    });

    await getCategory("ropa", "es", { products: 6 });

    expect(lastVariables()).toEqual({ slug: "ropa", first: 6 });
  });

  it("con 0 no pide los productos y devuelve la categoría con la lista vacía", async () => {
    // WordPress omite el campo: la respuesta llega sin `products`.
    fetchGraphQL.mockResolvedValue({ productCategory: CATEGORY });

    const result = await getCategory("ropa", "es", { products: 0 });

    expect(lastVariables()).toMatchObject({ slug: "ropa", withProducts: false });
    expect(String(fetchGraphQL.mock.calls.at(-1)?.[0])).toContain(
      "@include(if: $withProducts)",
    );
    expect(result!.category.name).toBe("Ropa");
    expect(result!.products).toEqual([]);
  });
});
