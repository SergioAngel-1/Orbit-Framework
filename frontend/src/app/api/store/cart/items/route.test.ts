import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { POST } from "./route";

// `cart/add-item` reenvía los detalles de línea (`extensions`) a la Store API y solo los ACOTA:
// claves y tamaños. La validación de fondo es la del validador declarado en WordPress.

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  writeCartToken: vi.fn(async () => undefined),
}));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  getCartRequestAuth: vi.fn(async () => ({ cartToken: "ct", authToken: "jwt" })),
}));
vi.mock("@/lib/woocommerce/complete-cart", () => ({
  completeStoreCart: vi.fn(async (cart: unknown) => cart),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function req(body: unknown) {
  return new Request("https://tienda.example/api/store/cart/items", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
}

/** Cuerpo que se envió a la Store API. */
function forwarded(): unknown {
  return (vi.mocked(storeFetch).mock.calls[0]![1] as { body: unknown }).body;
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(storeFetch).mockResolvedValue({
    data: { items: [] },
    cartToken: "ct",
  } as never);
});

describe("POST /api/store/cart/items — detalles de línea", () => {
  it("reenvía las extensiones declaradas a cart/add-item", async () => {
    const res = await POST(
      req({
        id: 10,
        quantity: 2,
        extensions: { intencion: "Paz", extras: ["a", "b"] },
      }),
    );

    expect(res.status).toBe(200);
    expect(storeFetch).toHaveBeenCalledWith(
      "/cart/add-item",
      expect.objectContaining({ method: "POST" }),
    );
    expect(forwarded()).toEqual({
      id: 10,
      quantity: 2,
      extensions: { intencion: "Paz", extras: ["a", "b"] },
    });
  });

  it("sin extensiones el cuerpo es el de siempre", async () => {
    await POST(req({ id: 10 }));

    expect(forwarded()).toEqual({ id: 10, quantity: 1 });
  });

  it.each([
    ["una clave con mayúsculas", { Intencion: "x" }],
    ["una clave que empieza por número", { "1x": "x" }],
    ["una clave demasiado larga", { [`a${"b".repeat(40)}`]: "x" }],
    ["un valor demasiado largo", { intencion: "x".repeat(1001) }],
    ["un valor objeto", { intencion: { a: 1 } }],
    ["una lista demasiado larga", { intencion: Array.from({ length: 21 }, () => "x") }],
    [
      "demasiadas claves",
      Object.fromEntries(Array.from({ length: 9 }, (_, i) => [`k${i}`, "x"])),
    ],
  ])("rechaza %s con 422 y no llama a WordPress", async (_label, extensions) => {
    const res = await POST(req({ id: 10, extensions }));

    expect(res.status).toBe(422);
    expect(storeFetch).not.toHaveBeenCalled();
  });
});
