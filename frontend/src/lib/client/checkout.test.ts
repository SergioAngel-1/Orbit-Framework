// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, renderHook } from "@testing-library/react";
import { checkoutApi, usePlaceOrder, type PlaceOrderInput } from "./checkout";
import { csrfFetch } from "./csrf";

// ============================================================================
//  Checkout del núcleo: la clave de idempotencia se reutiliza en los reintentos
//  de un mismo intento y se renueva tras crear el pedido; un segundo envío en
//  vuelo no sale; el cobro nunca lanza.
// ============================================================================

vi.mock("./csrf", () => ({ csrfFetch: vi.fn() }));

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });

const INPUT: PlaceOrderInput = {
  billing_address: { first_name: "A" },
  payment_method: "cod",
};
const keyOf = (call: number) =>
  (vi.mocked(csrfFetch).mock.calls[call][1].headers as Record<string, string>)[
    "Idempotency-Key"
  ];

beforeEach(() => {
  vi.mocked(csrfFetch).mockReset();
});
afterEach(cleanup);

describe("usePlaceOrder", () => {
  it("reintenta con la misma clave y usa otra tras crear el pedido", async () => {
    const { result } = renderHook(() => usePlaceOrder());
    vi.mocked(csrfFetch)
      .mockResolvedValueOnce(json(503, { error: "Caído." }))
      .mockResolvedValueOnce(json(200, { order_id: 7, status: "pending" }))
      .mockResolvedValueOnce(json(200, { order_id: 8, status: "pending" }));

    await act(async () => {
      await expect(result.current.placeOrder(INPUT)).rejects.toThrow("Caído.");
    });
    let order;
    await act(async () => {
      order = await result.current.placeOrder(INPUT);
    });
    expect(order).toEqual({ order_id: 7, status: "pending" });
    await act(async () => {
      await result.current.placeOrder(INPUT);
    });

    expect(keyOf(0)).toBe(keyOf(1));
    expect(keyOf(2)).not.toBe(keyOf(1));
    expect(csrfFetch).toHaveBeenCalledWith(
      "/api/store/checkout",
      expect.objectContaining({ method: "POST", body: INPUT }),
    );
  });

  it("un segundo envío mientras el primero vuela devuelve null y no sale", async () => {
    const { result } = renderHook(() => usePlaceOrder());
    let release!: (res: Response) => void;
    vi.mocked(csrfFetch).mockReturnValueOnce(new Promise((r) => (release = r)));

    let first!: Promise<unknown>;
    let second: unknown;
    await act(async () => {
      first = result.current.placeOrder(INPUT);
      second = await result.current.placeOrder(INPUT);
    });
    expect(second).toBeNull();
    expect(result.current.pending).toBe(true);

    await act(async () => {
      release(json(200, { order_id: 1, status: "pending" }));
      await first;
    });
    expect(csrfFetch).toHaveBeenCalledTimes(1);
    expect(result.current.pending).toBe(false);
  });
});

describe("checkoutApi.startPayment", () => {
  it("devuelve la redirección de la pasarela", async () => {
    vi.mocked(csrfFetch).mockResolvedValue(
      json(200, { mode: "redirect", redirectUrl: "https://pago.example/x" }),
    );
    await expect(
      checkoutApi.startPayment(5, "https://tienda.example/gracias"),
    ).resolves.toEqual({
      mode: "redirect",
      redirectUrl: "https://pago.example/x",
    });
  });

  it("sin pasarela aplicable o con la red caída: none, sin lanzar", async () => {
    vi.mocked(csrfFetch).mockResolvedValueOnce(json(401, { error: "No autenticado." }));
    await expect(checkoutApi.startPayment(5, "u")).resolves.toEqual({ mode: "none" });
    vi.mocked(csrfFetch).mockRejectedValueOnce(new TypeError("fetch failed"));
    await expect(checkoutApi.startPayment(5, "u")).resolves.toEqual({ mode: "none" });
  });
});
