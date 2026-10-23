import { beforeEach, describe, expect, it, vi } from "vitest";
import { NextResponse } from "next/server";
import { guardMutation } from "@/lib/api/guard";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { completeStoreCart } from "@/lib/woocommerce/complete-cart";
import { WooCommerceError } from "@/lib/woocommerce/client";
import { logger } from "@/lib/observability/logger";
import { DELETE, POST } from "./route";

// ============================================================================
//  POST y DELETE /api/store/cart/points.
//
//  El navegador solo declara CUÁNTOS puntos quiere usar; el importe lo calcula
//  WordPress. El BFF reenvía la cantidad al update callback de la extensión
//  `hwe-loyalty` con el `Cart-Token` y el JWT de la sesión, y devuelve el
//  carrito recalculado. Sin sesión no hay canje.
// ============================================================================

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/cart-session", () => ({ getCartRequestAuth: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  writeCartToken: vi.fn(async () => undefined),
}));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/complete-cart", () => ({
  completeStoreCart: vi.fn(async (cart: unknown) => cart),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const state = vi.hoisted(() => ({
  site: {
    modules: { loyalty: true as boolean },
    loyalty: { points_enabled: true as boolean },
  },
}));

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn(async () => state.site) }));

const CART = {
  items: [],
  items_count: 0,
  totals: { total_price: "0" },
};

const post = (body: unknown) =>
  new Request("https://tienda.example/api/store/cart/points", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });

const remove = () =>
  new Request("https://tienda.example/api/store/cart/points", { method: "DELETE" });

/** Llamada al update callback de la extensión. */
const extensionCall = () =>
  vi.mocked(storeFetch).mock.calls.find(([path]) => path === "/cart/extensions");

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.loyalty = true;
  state.site.loyalty.points_enabled = true;
  vi.mocked(guardMutation).mockResolvedValue(null);
  vi.mocked(getCartRequestAuth).mockResolvedValue({
    cartToken: "cart-token",
    authToken: "jwt-del-cliente",
    userId: "7",
  });
  vi.mocked(storeFetch).mockResolvedValue({
    data: CART,
    cartToken: "next-token",
  } as never);
});

describe("POST /api/store/cart/points", () => {
  it("llama al update callback con puntos, Cart-Token y JWT y devuelve el carrito", async () => {
    const res = await POST(post({ points: 250 }));

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(CART);
    expect(storeFetch).toHaveBeenCalledWith("/cart/extensions", {
      method: "POST",
      body: { namespace: "hwe-loyalty", data: { points: 250 } },
      cartToken: "cart-token",
      authToken: "jwt-del-cliente",
    });
    expect(writeCartToken).toHaveBeenCalledWith("next-token");
    expect(completeStoreCart).toHaveBeenCalledWith(CART, expect.any(String));
  });

  it("pasa por guardMutation con el límite del carrito", async () => {
    await POST(post({ points: 10 }));

    expect(guardMutation).toHaveBeenCalledWith(
      expect.any(Request),
      expect.objectContaining({
        rateLimit: expect.objectContaining({ name: "cart" }),
      }),
    );
  });

  it("el navegador nunca decide el importe: las claves de más se descartan", async () => {
    await POST(post({ points: 10, discount: 99999, total: "0" }));

    expect(extensionCall()![1]!.body).toEqual({
      namespace: "hwe-loyalty",
      data: { points: 10 },
    });
  });

  it.each([
    ["negativo", { points: -1 }],
    ["decimal", { points: 1.5 }],
    ["texto", { points: "10" }],
    ["ausente", {}],
  ])("rechaza %s con 422 y no toca WordPress", async (_label, body) => {
    const res = await POST(post(body));

    expect(res.status).toBe(422);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("con «Puntos» apagado responde 404 y no toca WordPress", async () => {
    state.site.loyalty.points_enabled = false;

    const res = await POST(post({ points: 10 }));

    expect(res.status).toBe(404);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("con el módulo apagado responde 404 y no toca WordPress", async () => {
    state.site.modules.loyalty = false;

    const res = await POST(post({ points: 10 }));

    expect(res.status).toBe(404);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("sin sesión responde 401: un invitado no canjea", async () => {
    vi.mocked(getCartRequestAuth).mockResolvedValue({
      cartToken: "cart-token",
      authToken: null,
      userId: null,
    });

    const res = await POST(post({ points: 10 }));

    expect(res.status).toBe(401);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("si guardMutation bloquea, no se llama al update callback", async () => {
    vi.mocked(guardMutation).mockResolvedValue(
      NextResponse.json({ error: "Token CSRF inválido." }, { status: 403 }),
    );

    const res = await POST(post({ points: 10 }));

    expect(res.status).toBe(403);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("un 400 de WordPress por cantidad inválida se propaga como 400", async () => {
    vi.mocked(storeFetch).mockRejectedValue(
      new WooCommerceError(
        "La cantidad no es válida.",
        400,
        "hwe_loyalty_invalid_points",
      ),
    );

    const res = await POST(post({ points: 10 }));

    expect(res.status).toBe(400);
    expect(await res.json()).toMatchObject({
      code: "hwe_loyalty_invalid_points",
    });
    expect(writeCartToken).not.toHaveBeenCalled();
  });

  it("un fallo de WordPress se registra sin datos personales", async () => {
    vi.mocked(storeFetch).mockRejectedValue(
      new WooCommerceError("La tienda no responde.", 502, "wc_store_network"),
    );

    const res = await POST(post({ points: 10 }));

    expect(res.status).toBe(502);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({ event: "cart.points.error", points: 10 }),
      expect.any(String),
    );
  });
});

describe("DELETE /api/store/cart/points", () => {
  it("quita el canje enviando 0 al update callback", async () => {
    const res = await DELETE(remove());

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(CART);
    expect(extensionCall()![1]).toMatchObject({
      method: "POST",
      body: { namespace: "hwe-loyalty", data: { points: 0 } },
      cartToken: "cart-token",
      authToken: "jwt-del-cliente",
    });
    expect(writeCartToken).toHaveBeenCalledWith("next-token");
  });

  it("pasa por guardMutation", async () => {
    await DELETE(remove());

    expect(guardMutation).toHaveBeenCalled();
  });

  it("con el módulo o «Puntos» apagado responde 404", async () => {
    state.site.loyalty.points_enabled = false;
    expect((await DELETE(remove())).status).toBe(404);

    state.site.modules.loyalty = false;
    expect((await DELETE(remove())).status).toBe(404);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("sin sesión responde 401", async () => {
    vi.mocked(getCartRequestAuth).mockResolvedValue({
      cartToken: "cart-token",
      authToken: null,
      userId: null,
    });

    const res = await DELETE(remove());

    expect(res.status).toBe(401);
    expect(storeFetch).not.toHaveBeenCalled();
  });
});
