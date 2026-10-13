import { describe, it, expect, vi, beforeEach } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { getSiteConfig } from "@/lib/config";
import { DELETE, POST } from "./route";

// POST /api/store/coupons: la sesión la exige cada cupón en WordPress, no el BFF.
// Con los cupones desactivados en el Control Center no se aplica ninguno, pero
// el que ya estaba en el carrito se puede quitar (un descuento que no se pudiera
// quitar dejaría al cliente atrapado).

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/cart-session", () => ({ getCartRequestAuth: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({ writeCartToken: vi.fn() }));
vi.mock("@/lib/woocommerce/complete-cart", () => ({
  completeStoreCart: async (cart: unknown) => cart,
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function request(method: string, body: unknown): Request {
  return new Request("http://localhost:3000/api/store/coupons", {
    method,
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
}

const config = (couponsEnabled: boolean) =>
  vi.mocked(getSiteConfig).mockResolvedValue({
    ecommerce: { coupons_enabled: couponsEnabled },
  } as never);

beforeEach(() => {
  vi.clearAllMocks();
  config(true);
  vi.mocked(getCartRequestAuth).mockResolvedValue({
    cartToken: "cart-token",
    authToken: null,
    userId: null,
  });
  vi.mocked(storeFetch).mockResolvedValue({
    data: { items: [] },
    cartToken: "cart-token",
  } as never);
});

describe("/api/store/coupons", () => {
  it("POST sin sesión pasa el cupón a la Store API sin token de cuenta", async () => {
    const res = await POST(request("POST", { code: "BIENVENIDA10" }));

    expect(res.status).toBe(200);
    expect(storeFetch).toHaveBeenCalledWith(
      "/cart/apply-coupon",
      expect.objectContaining({ authToken: null, cartToken: "cart-token" }),
    );
  });

  it("POST con sesión aplica el cupón con el JWT del cliente", async () => {
    vi.mocked(getCartRequestAuth).mockResolvedValue({
      cartToken: "cart-token",
      authToken: "jwt",
      userId: "7",
    });

    await POST(request("POST", { code: "BIENVENIDA10" }));

    expect(storeFetch).toHaveBeenCalledWith(
      "/cart/apply-coupon",
      expect.objectContaining({ authToken: "jwt" }),
    );
  });

  it("POST con los cupones desactivados responde 403 y no toca el carrito", async () => {
    config(false);

    const res = await POST(request("POST", { code: "BIENVENIDA10" }));

    expect(res.status).toBe(403);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("DELETE con los cupones desactivados sigue dejando quitar el aplicado", async () => {
    config(false);

    const res = await DELETE(request("DELETE", { code: "BIENVENIDA10" }));

    expect(res.status).toBe(200);
    expect(storeFetch).toHaveBeenCalledWith("/cart/remove-coupon", expect.anything());
  });

  it("un código inválido es un 422", async () => {
    const res = await POST(request("POST", { code: "" }));

    expect(res.status).toBe(422);
  });
});
