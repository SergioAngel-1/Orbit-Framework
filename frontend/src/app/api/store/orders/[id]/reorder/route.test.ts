import { beforeEach, describe, expect, it, vi } from "vitest";
import { requireSession } from "@/lib/auth/session";
import { wcFetch, WooCommerceError } from "@/lib/woocommerce/client";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { POST } from "./route";

// ============================================================================
//  POST /api/store/orders/[id]/reorder — «Volver a pedir». Solo el dueño del
//  pedido; añade al carrito lo que hoy se puede comprar y dice qué no entró.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({
  requireSession: vi.fn(),
  getSession: vi.fn(async () => ({ userId: "7", token: "jwt" })),
}));
vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  readCartToken: vi.fn(async () => "cart-token"),
  writeCartToken: vi.fn(async () => undefined),
  clearCartToken: vi.fn(async () => undefined),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/woocommerce/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/woocommerce/client")>()),
  wcFetch: vi.fn(),
}));

const CART = { items: [], items_count: 0, coupons: [], totals: { total_price: "0" } };

const order = (overrides: Record<string, unknown> = {}) => ({
  id: 10,
  customer_id: 7,
  status: "completed",
  line_items: [
    { id: 1, name: "Camiseta", product_id: 3, quantity: 2 },
    { id: 2, name: "Gorra", product_id: 4, quantity: 1 },
  ],
  ...overrides,
});

const product = (id: number, overrides: Record<string, unknown> = {}) => ({
  id,
  status: "publish",
  purchasable: true,
  stock_status: "instock",
  manage_stock: false,
  stock_quantity: null,
  backorders_allowed: false,
  ...overrides,
});

const call = (id = "10") =>
  POST(
    new Request("https://tienda.example/api/store/orders/10/reorder", {
      method: "POST",
    }),
    {
      params: Promise.resolve({ id }),
    },
  );

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(requireSession).mockResolvedValue({ userId: "7", token: "jwt" } as never);
  vi.mocked(wcFetch).mockImplementation((async (path: string) =>
    path === "/products" ? [product(3), product(4)] : order()) as never);
  vi.mocked(storeFetch).mockResolvedValue({ data: CART, cartToken: "nuevo" } as never);
});

describe("POST /api/store/orders/[id]/reorder", () => {
  it("añade al carrito las líneas que hoy se pueden comprar", async () => {
    const res = await call();
    const body = await res.json();

    expect(res.status).toBe(200);
    const adds = vi
      .mocked(storeFetch)
      .mock.calls.filter(([p]) => p === "/cart/add-item");
    expect(adds.map(([, o]) => (o as { body: unknown }).body)).toEqual([
      { id: 3, quantity: 2 },
      { id: 4, quantity: 1 },
    ]);
    expect(body.added).toHaveLength(2);
    expect(body.skipped).toEqual([]);
  });

  it("dice qué línea no entró y por qué, y sigue con las demás", async () => {
    vi.mocked(wcFetch).mockImplementation((async (path: string) =>
      path === "/products"
        ? [product(3, { stock_status: "outofstock" }), product(4)]
        : order()) as never);

    const body = await (await call()).json();

    expect(body.added.map((a: { productId: number }) => a.productId)).toEqual([4]);
    expect(body.skipped).toEqual([{ name: "Camiseta", reason: "out_of_stock" }]);
  });

  it("un rechazo de la Store API en una línea no aborta el resto", async () => {
    vi.mocked(storeFetch)
      .mockRejectedValueOnce(
        new WooCommerceError("Sin stock", 400, "woocommerce_rest_product_out_of_stock"),
      )
      .mockResolvedValue({ data: CART, cartToken: "nuevo" } as never);

    const body = await (await call()).json();

    expect(body.skipped).toEqual([{ name: "Camiseta", reason: "out_of_stock" }]);
    expect(body.added).toHaveLength(1);
  });

  it("el pedido de otro cliente es un 404 y no toca el carrito", async () => {
    vi.mocked(wcFetch).mockResolvedValue(order({ customer_id: 8 }) as never);

    const res = await call();

    expect(res.status).toBe(404);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("el JWT va a la Store API: el carrito es el de la cuenta", async () => {
    await call();

    expect(vi.mocked(storeFetch).mock.calls[0]![1]).toMatchObject({ authToken: "jwt" });
  });

  it("sin sesión, 401", async () => {
    vi.mocked(requireSession).mockRejectedValue(new Error("UNAUTHENTICATED"));

    expect((await call()).status).toBe(401);
  });
});
