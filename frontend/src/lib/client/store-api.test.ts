// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from "vitest";
import { BffError } from "./bff-error";
import { csrfFetch } from "./csrf";
import { cartApi } from "./store-api";

// ============================================================================
//  cartApi: un error del BFF sale como BffError (estado, código y cuerpo), con el
//  mismo mensaje que antes. `useCartStore().failure` lo entrega tal cual a la vista.
// ============================================================================

vi.mock("./csrf", () => ({ csrfFetch: vi.fn() }));

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });

beforeEach(() => {
  vi.mocked(csrfFetch).mockReset();
});

describe("cartApi", () => {
  it("un error del BFF lleva estado, código y cuerpo", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(
      json(409, {
        error: "Sin stock.",
        code: "woocommerce_rest_cart_invalid",
        available: 1,
      }),
    );
    const error = (await cartApi
      .updateItem("k", 5)
      .catch((e: unknown) => e)) as BffError;

    expect(error).toBeInstanceOf(BffError);
    expect(error.message).toBe("Sin stock.");
    expect(error.status).toBe(409);
    expect(error.code).toBe("woocommerce_rest_cart_invalid");
    expect(error.body.available).toBe(1);
  });

  it("sin mensaje del BFF conserva el texto anterior («Error <estado>»)", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(new Response("", { status: 502 }));
    await expect(cartApi.removeItem("k")).rejects.toThrow("Error 502");
  });
});
