import { beforeEach, describe, expect, it, vi } from "vitest";

// ============================================================================
//  El catálogo pide el requisito de membresía SOLO con el módulo activo.
//
//  Con el módulo apagado el campo `hweMembership` no existe en el esquema de
//  WordPress: pedirlo haría fallar toda la consulta. Por eso hay dos variantes
//  del documento y el lector tolera que el campo no venga (nodos antiguos, stub
//  de CI o el módulo apagado a mitad de una caché).
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

import { membershipsCatalogEnabled } from "@/lib/memberships/catalog-flag";
import { getCategory, getProducts, getProductBySlug } from "./products";

const node = (over: Record<string, unknown> = {}) => ({
  id: "p1",
  databaseId: 1,
  name: "Camiseta",
  slug: "camiseta",
  type: "SIMPLE",
  image: null,
  ...over,
});

const EMPTY = {
  products: { pageInfo: { hasNextPage: false, endCursor: null }, nodes: [] },
};

beforeEach(() => {
  fetchGraphQL.mockReset();
  vi.mocked(membershipsCatalogEnabled).mockResolvedValue(false);
});

describe("variante de la consulta", () => {
  it("con el módulo apagado NO pide `hweMembership`", async () => {
    fetchGraphQL.mockResolvedValue(EMPTY);

    await getProducts();

    const document = fetchGraphQL.mock.calls[0]![0] as string;
    expect(document).not.toContain("hweMembership");
  });

  it("con el módulo encendido pide `hweMembership` en productos y categorías", async () => {
    vi.mocked(membershipsCatalogEnabled).mockResolvedValue(true);
    fetchGraphQL.mockResolvedValueOnce(EMPTY).mockResolvedValueOnce({
      productCategory: {
        id: "c1",
        databaseId: 3,
        name: "Ropa",
        slug: "ropa",
        products: { nodes: [] },
      },
    });

    await getProducts();
    await getCategory("ropa");

    for (const [document] of fetchGraphQL.mock.calls) {
      expect(document as string).toContain("hweMembership");
      expect(document as string).toContain("requiredLevel");
    }
  });
});

describe("normalización del requisito", () => {
  it("sin campo del módulo, el producto queda sin requisito", async () => {
    fetchGraphQL.mockResolvedValue({
      products: { ...EMPTY.products, nodes: [node()] },
    });

    const page = await getProducts();

    expect(page.products[0]!.membership).toBeNull();
  });

  it("con el módulo encendido expone el requisito ya normalizado", async () => {
    vi.mocked(membershipsCatalogEnabled).mockResolvedValue(true);
    fetchGraphQL.mockResolvedValue({
      products: {
        ...EMPTY.products,
        nodes: [
          node({
            hweMembership: {
              requiredLevel: { slug: "socio", rank: 2, name: "Socio" },
              opensAt: "2026-11-01T00:00:00Z",
              locked: true,
            },
          }),
        ],
      },
    });

    const page = await getProducts();

    expect(page.products[0]!.membership).toEqual({
      requiredLevel: { slug: "socio", rank: 2, name: "Socio" },
      opensAt: "2026-11-01T00:00:00Z",
      locked: true,
    });
  });

  it("un campo nulo o con forma inválida no rompe el catálogo", async () => {
    fetchGraphQL.mockResolvedValue({
      products: {
        ...EMPTY.products,
        nodes: [
          node({ hweMembership: null }),
          node({ id: "p2", databaseId: 2, hweMembership: { requiredLevel: "x" } }),
        ],
      },
    });

    const page = await getProducts();

    expect(page.products.map((product) => product.membership)).toEqual([
      null,
      expect.objectContaining({ requiredLevel: null }),
    ]);
  });

  it("la ficha de producto y la categoría también conservan el requisito", async () => {
    vi.mocked(membershipsCatalogEnabled).mockResolvedValue(true);
    fetchGraphQL
      .mockResolvedValueOnce({
        product: node({
          hweMembership: {
            requiredLevel: { slug: "socio", rank: 2, name: "Socio" },
            opensAt: null,
            locked: true,
          },
        }),
      })
      .mockResolvedValueOnce({
        productCategory: {
          id: "c1",
          databaseId: 3,
          name: "Ropa",
          slug: "ropa",
          hweMembership: {
            requiredLevel: { slug: "socio", rank: 1, name: "Socio" },
            opensAt: null,
            locked: false,
          },
          products: { nodes: [] },
        },
      });

    const product = await getProductBySlug("camiseta");
    const category = await getCategory("ropa");

    expect(product!.membership?.locked).toBe(true);
    expect(category!.category.membership?.requiredLevel?.rank).toBe(1);
  });
});
