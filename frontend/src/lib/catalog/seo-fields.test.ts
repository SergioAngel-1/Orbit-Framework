import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...a: unknown[]) => fetchGraphQL(...a),
}));

import { getCategory, getProductBySlug } from "./products";

// Los campos `seoTitle` / `seoDescription` son la caja SEO del editor (mu-plugin
// hwe-seo). La query ya los pedía, pero la normalización los tiraba y el
// override del panel nunca llegaba a `<title>` ni a la meta description.
describe("override SEO del editor", () => {
  beforeEach(() => fetchGraphQL.mockReset());

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
