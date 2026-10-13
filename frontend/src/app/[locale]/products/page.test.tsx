import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ReactElement, ReactNode } from "react";
import { getSiteConfig } from "@/lib/config";
import { getProducts } from "@/lib/catalog/products";
import ProductsPage from "./page";

// ============================================================================
//  Listado de la tienda: la búsqueda es de `ecommerce.search_enabled`. Apagada,
//  la vista no pinta el campo y tampoco busca por un `?search=` escrito a mano.
// ============================================================================

vi.mock("next-intl/server", () => ({
  getTranslations: vi.fn(async () => (key: string) => key),
  getLocale: vi.fn(async () => "es"),
}));
vi.mock("@/i18n/navigation", () => ({ Link: () => null }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/catalog/products", () => ({
  getProducts: vi.fn(),
  getCategories: vi.fn(async () => []),
}));
vi.mock("@/components/products/infinite-product-grid", () => ({
  InfiniteProductGrid: () => null,
}));

type El = ReactElement<{ children?: ReactNode } & Record<string, unknown>>;

/** Elementos del árbol (no los de tipo función) que cumplen el predicado. */
function find(node: ReactNode, test: (el: El) => boolean, out: El[] = []): El[] {
  if (!node || typeof node !== "object") return out;
  if (Array.isArray(node)) {
    node.forEach((n) => find(n, test, out));
    return out;
  }
  const el = node as El;
  if (test(el)) out.push(el);
  find(el.props?.children, test, out);
  return out;
}

const searchInputs = (tree: ReactNode) =>
  find(tree, (el) => el.type === "input" && el.props.name === "search");

function config(searchEnabled: boolean) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    brand: { url: "https://tienda.example" },
    ecommerce: { search_enabled: searchEnabled, wishlist_enabled: false },
  } as never);
}

const render = (search?: string) =>
  ProductsPage({ searchParams: Promise.resolve(search ? { search } : {}) });

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getProducts).mockResolvedValue({
    products: [],
    pageInfo: { hasNextPage: false, endCursor: null },
  } as never);
});

describe("ProductsPage: búsqueda", () => {
  it("encendida pinta el campo y busca por el texto pedido", async () => {
    config(true);

    const tree = await render("camiseta");

    expect(searchInputs(tree)).toHaveLength(1);
    expect(getProducts).toHaveBeenCalledWith(
      expect.objectContaining({ search: "camiseta" }),
    );
  });

  it("apagada no pinta el campo", async () => {
    config(false);

    expect(searchInputs(await render())).toHaveLength(0);
  });

  it("apagada ignora un `?search=` escrito a mano", async () => {
    config(false);

    await render("camiseta");

    expect(getProducts).toHaveBeenCalledWith(
      expect.objectContaining({ search: undefined }),
    );
  });
});
