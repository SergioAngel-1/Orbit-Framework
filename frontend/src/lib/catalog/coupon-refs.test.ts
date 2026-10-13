import { beforeEach, describe, expect, it, vi } from "vitest";
import { fetchGraphQL } from "@/lib/graphql-client";
import { getCartProductRefs, getCategoryPaths } from "./coupon-refs";

// Lo que el BFF necesita del catálogo para decidir qué cupones públicos aplican a
// un producto o a una categoría: el slug y las categorías CON sus ancestros
// (WooCommerce aplica una categoría a toda su descendencia).

vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

beforeEach(() => vi.clearAllMocks());

describe("getCategoryPaths", () => {
  it("cada categoría con sus ancestros", async () => {
    vi.mocked(fetchGraphQL).mockResolvedValue({
      productCategories: {
        nodes: [
          { slug: "bebidas", ancestors: null },
          { slug: "cafe", ancestors: { nodes: [{ slug: "bebidas" }] } },
        ],
      },
    });

    const paths = await getCategoryPaths();

    expect(paths.get("cafe")).toEqual(["cafe", "bebidas"]);
    expect(paths.get("bebidas")).toEqual(["bebidas"]);
  });

  it("si el catálogo no responde, no hay árbol (el cupón se anuncia sin ancestros)", async () => {
    vi.mocked(fetchGraphQL).mockRejectedValue(new Error("caído"));

    expect((await getCategoryPaths()).size).toBe(0);
  });
});

describe("getCartProductRefs", () => {
  it("slug y categorías con ancestros, por id de producto", async () => {
    vi.mocked(fetchGraphQL).mockResolvedValue({
      products: {
        nodes: [
          {
            databaseId: 3,
            slug: "camiseta",
            productCategories: {
              nodes: [{ slug: "cafe", ancestors: { nodes: [{ slug: "bebidas" }] } }],
            },
          },
        ],
      },
    });

    const refs = await getCartProductRefs([3, 3, 9]);

    expect(refs.get(3)).toEqual({
      slug: "camiseta",
      categorySlugs: ["cafe", "bebidas"],
    });
    expect(refs.has(9)).toBe(false);
    expect(vi.mocked(fetchGraphQL).mock.calls[0]![1]!.variables).toMatchObject({
      include: [3, 9],
    });
  });

  it("sin ids no consulta nada", async () => {
    expect((await getCartProductRefs([])).size).toBe(0);
    expect(fetchGraphQL).not.toHaveBeenCalled();
  });

  it("si falla, devuelve vacío en vez de romper el carrito", async () => {
    vi.mocked(fetchGraphQL).mockRejectedValue(new Error("caído"));

    expect((await getCartProductRefs([3])).size).toBe(0);
  });
});
