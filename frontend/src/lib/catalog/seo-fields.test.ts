import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...a: unknown[]) => fetchGraphQL(...a),
}));
// Sin el módulo `memberships` activo, la consulta del catálogo no pide su campo.
vi.mock("@/lib/memberships/catalog-flag", () => ({
  membershipsCatalogEnabled: vi.fn(async () => false),
}));

import { getCategory, getProductBySlug, getProductsBySlugs } from "./products";

// Los campos `seoTitle` / `seoDescription` son la caja SEO del editor (mu-plugin
// hwe-seo). La query ya los pedía, pero la normalización los tiraba y el
// override del panel nunca llegaba a `<title>` ni a la meta description.
describe("override SEO del editor", () => {
  beforeEach(() => {
    fetchGraphQL.mockReset();
  });

  it("la ficha de producto conserva seoTitle y seoDescription", async () => {
    fetchGraphQL.mockResolvedValue({
      product: {
        id: "1",
        databaseId: 7,
        name: "Camiseta",
        slug: "camiseta",
        type: "SIMPLE",
        seoTitle: "Camiseta de algodón",
        seoDescription: "La camiseta básica.",
      },
    });

    const product = await getProductBySlug("camiseta");

    expect(product?.seoTitle).toBe("Camiseta de algodón");
    expect(product?.seoDescription).toBe("La camiseta básica.");
  });

  it("sin override, null (no cadena vacía)", async () => {
    fetchGraphQL.mockResolvedValue({
      product: {
        id: "1",
        databaseId: 7,
        name: "Camiseta",
        slug: "camiseta",
        type: "SIMPLE",
      },
    });

    const product = await getProductBySlug("camiseta");

    expect(product?.seoTitle).toBeNull();
    expect(product?.seoDescription).toBeNull();
  });

  it("la categoría conserva seoTitle y seoDescription", async () => {
    fetchGraphQL.mockResolvedValue({
      productCategory: {
        id: "c",
        databaseId: 3,
        name: "Ropa",
        slug: "ropa",
        description: null,
        seoTitle: "Ropa de verano",
        seoDescription: "Toda la ropa.",
        products: { nodes: [] },
      },
    });

    const result = await getCategory("ropa");

    expect(result?.category.seoTitle).toBe("Ropa de verano");
    expect(result?.category.seoDescription).toBe("Toda la ropa.");
  });
});

describe("getProductsBySlugs", () => {
  beforeEach(() => {
    fetchGraphQL.mockReset();
  });

  it("devuelve en el orden pedido y omite los que no existen", async () => {
    fetchGraphQL.mockResolvedValue({
      products: {
        nodes: [
          { id: "2", databaseId: 2, name: "Gorra", slug: "gorra", type: "SIMPLE" },
          {
            id: "1",
            databaseId: 1,
            name: "Camiseta",
            slug: "camiseta",
            type: "SIMPLE",
          },
        ],
      },
    });

    const products = await getProductsBySlugs(["camiseta", "fantasma", "gorra"]);

    expect(products.map((p) => p.slug)).toEqual(["camiseta", "gorra"]);
    expect(fetchGraphQL.mock.calls[0]![1].variables.slugs).toEqual([
      "camiseta",
      "fantasma",
      "gorra",
    ]);
  });

  it("sin slugs no consulta", async () => {
    expect(await getProductsBySlugs([])).toEqual([]);
    expect(fetchGraphQL).not.toHaveBeenCalled();
  });
});
