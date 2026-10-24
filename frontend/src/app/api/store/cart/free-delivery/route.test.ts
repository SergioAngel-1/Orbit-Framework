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
//  POST y DELETE /api/store/cart/free-delivery.
//
//  El navegador solo declara «usar / no usar» un envío gratis; el método HTTP
//  es la declaración y el cuerpo se ignora por completo. El importe, el cupo y
//  la decisión los calcula WordPress en la extensión `hwe-memberships`. El BFF
//  reenvía el booleano al update callback con el `Cart-Token` y el JWT de la
//  sesión, y devuelve el carrito recalculado.
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
  site: { modules: { memberships: true as boolean } },
}));

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn(async () => state.site) }));

const CART = {
  items: [],
  items_count: 0,
  totals: { total_price: "0" },
  extensions: {
    "hwe-memberships": {
      level: null,
      member_discount: { total: "0", lines: {} },
      free_delivery: {
        available: true,
        remaining: 2,
        requested: true,
        applied: true,
        reason: "membership_free_delivery",
      },
      blocked_items: [],
      purchase_notice: null,
    },
  },
};

const post = (body?: unknown) =>
  new Request("https://tienda.example/api/store/cart/free-delivery", {
    method: "POST",
    ...(body === undefined
      ? {}
      : {
          headers: { "content-type": "application/json" },
          body: JSON.stringify(body),
        }),
  });

const remove = () =>
  new Request("https://tienda.example/api/store/cart/free-delivery", {
    method: "DELETE",
  });

/** Llamada al update callback de la extensión. */
const extensionCall = () =>
  vi.mocked(storeFetch).mock.calls.find(([path]) => path === "/cart/extensions");

beforeEach(() => {
  vi.clearAllMocks();
  state.site.modules.memberships = true;
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

describe("POST /api/store/cart/free-delivery", () => {
  it("declara el uso y devuelve el carrito recalculado", async () => {
    const res = await POST(post());

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(CART);
    expect(storeFetch).toHaveBeenCalledWith("/cart/extensions", {
      method: "POST",
      body: { namespace: "hwe-memberships", data: { free_delivery: true } },
      cartToken: "cart-token",
      authToken: "jwt-del-cliente",
    });
    expect(writeCartToken).toHaveBeenCalledWith("next-token");
    expect(completeStoreCart).toHaveBeenCalledWith(CART, expect.any(String));
  });

  it("pasa por guardMutation con el límite del carrito", async () => {
    await POST(post());

    expect(guardMutation).toHaveBeenCalledWith(
      expect.any(Request),
      expect.objectContaining({
        rateLimit: expect.objectContaining({ name: "cart" }),
      }),
    );
  });

  it("el cuerpo no decide nada: cualquier clave de más se ignora", async () => {
    await POST(post({ free_delivery: false, discount: 99999, total: "0" }));

    expect(extensionCall()![1]!.body).toEqual({
      namespace: "hwe-memberships",
      data: { free_delivery: true },
    });
  });

  it("con el módulo apagado responde 404 y no toca WordPress", async () => {
    state.site.modules.memberships = false;

    const res = await POST(post());

    expect(res.status).toBe(404);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("sin sesión responde 401: un invitado no usa el beneficio", async () => {
    vi.mocked(getCartRequestAuth).mockResolvedValue({
      cartToken: "cart-token",
      authToken: null,
      userId: null,
    });

    const res = await POST(post());

    expect(res.status).toBe(401);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("si guardMutation bloquea (sin CSRF), no se llama al update callback", async () => {
    vi.mocked(guardMutation).mockResolvedValue(
      NextResponse.json({ error: "Token CSRF inválido." }, { status: 403 }),
    );

    const res = await POST(post());

    expect(res.status).toBe(403);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("un error del módulo sale como 409 con su motivo estable", async () => {
    vi.mocked(storeFetch).mockRejectedValue(
      new WooCommerceError(
        "No queda ningún envío gratis disponible en tu membresía.",
        409,
        "hwe_membership_free_delivery_unavailable",
        { status: 409, reason: "membership_free_delivery_unavailable" },
      ),
    );

    const res = await POST(post());

    expect(res.status).toBe(409);
    expect(await res.json()).toMatchObject({
      reason: "membership_free_delivery_unavailable",
    });
    expect(writeCartToken).not.toHaveBeenCalled();
  });

  it("un error ajeno sigue su camino actual", async () => {
    vi.mocked(storeFetch).mockRejectedValue(
      new WooCommerceError(
        "La cantidad no es válida.",
        400,
        "woocommerce_rest_invalid_product",
      ),
    );

    const res = await POST(post());

    expect(res.status).toBe(400);
    expect(await res.json()).toMatchObject({
      code: "woocommerce_rest_invalid_product",
    });
  });

  it("un fallo se registra sin datos personales", async () => {
    vi.mocked(storeFetch).mockRejectedValue(
      new WooCommerceError("La tienda no responde.", 502, "wc_store_network"),
    );

    const res = await POST(post());

    expect(res.status).toBe(502);
    expect(logger.error).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "cart.free_delivery.error",
        requested: true,
      }),
      expect.any(String),
    );
  });
});

describe("DELETE /api/store/cart/free-delivery", () => {
  it("quita el uso enviando false al update callback", async () => {
    const res = await DELETE(remove());

    expect(res.status).toBe(200);
    expect(await res.json()).toEqual(CART);
    expect(extensionCall()![1]).toMatchObject({
      method: "POST",
      body: { namespace: "hwe-memberships", data: { free_delivery: false } },
      cartToken: "cart-token",
      authToken: "jwt-del-cliente",
    });
    expect(writeCartToken).toHaveBeenCalledWith("next-token");
  });

  it("pasa por guardMutation", async () => {
    await DELETE(remove());

    expect(guardMutation).toHaveBeenCalled();
  });

  it("con el módulo apagado responde 404", async () => {
    state.site.modules.memberships = false;

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

  it("traduce un error del módulo a 409", async () => {
    vi.mocked(storeFetch).mockRejectedValue(
      new WooCommerceError(
        "No puedes usar este beneficio.",
        409,
        "hwe_membership_required",
        { reason: "membership_required" },
      ),
    );

    const res = await DELETE(remove());

    expect(res.status).toBe(409);
    expect((await res.json()).reason).toBe("membership_required");
  });
});
