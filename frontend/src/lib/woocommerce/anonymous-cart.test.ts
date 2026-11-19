import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { WooCommerceError } from "@/lib/woocommerce/client";
import { logger } from "@/lib/observability/logger";
import { getAnonymousEmptyCart, resetAnonymousCartCache } from "./anonymous-cart";

// Carrito vacío de un visitante sin cookie ni sesión: se lee UNA vez de la Store
// API (sin token), se cachea en memoria con TTL y single-flight, y nunca se
// guarda el token que devuelve esa lectura.

vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), info: vi.fn(), error: vi.fn() },
}));

const EMPTY = {
  items: [],
  items_count: 0,
  coupons: [],
  shipping_rates: [],
  totals: { total_price: "0" },
} as never;

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(storeFetch).mockReset();
  resetAnonymousCartCache();
  vi.mocked(storeFetch).mockResolvedValue({
    data: EMPTY,
    cartToken: "token-de-sesion",
  } as never);
});

afterEach(() => {
  vi.useRealTimers();
});

describe("getAnonymousEmptyCart", () => {
  it("lee la Store API SIN token: no abre sesión de WooCommerce", async () => {
    const cart = await getAnonymousEmptyCart();

    expect(cart).toBe(EMPTY);
    expect(storeFetch).toHaveBeenCalledTimes(1);
    expect(storeFetch).toHaveBeenCalledWith("/cart");
  });

  it("la segunda llamada sale de la copia en memoria", async () => {
    await getAnonymousEmptyCart();
    await getAnonymousEmptyCart();

    expect(storeFetch).toHaveBeenCalledTimes(1);
  });

  it("al caducar el TTL vuelve a leer una sola vez", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-01-01T00:00:00Z"));
    await getAnonymousEmptyCart();

    vi.setSystemTime(new Date("2026-01-01T00:04:59Z"));
    await getAnonymousEmptyCart();
    expect(storeFetch).toHaveBeenCalledTimes(1);

    vi.setSystemTime(new Date("2026-01-01T00:05:01Z"));
    await getAnonymousEmptyCart();
    expect(storeFetch).toHaveBeenCalledTimes(2);
  });

  it("llamadas concurrentes comparten una sola lectura en vuelo", async () => {
    let resolveRead!: (value: { data: unknown; cartToken: string }) => void;
    vi.mocked(storeFetch).mockReturnValueOnce(
      new Promise((resolve) => {
        resolveRead = resolve;
      }) as never,
    );

    const first = getAnonymousEmptyCart();
    const second = getAnonymousEmptyCart();
    expect(storeFetch).toHaveBeenCalledTimes(1);

    resolveRead({ data: EMPTY, cartToken: "token-de-sesion" });

    await expect(first).resolves.toBe(EMPTY);
    await expect(second).resolves.toBe(EMPTY);
    expect(storeFetch).toHaveBeenCalledTimes(1);
  });

  it("si WordPress falla y hay copia, sirve la copia", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-01-01T00:00:00Z"));
    await getAnonymousEmptyCart();

    vi.setSystemTime(new Date("2026-01-01T00:06:00Z"));
    vi.mocked(storeFetch).mockRejectedValueOnce(
      new WooCommerceError("Store API caída", 503, "wc_store_network"),
    );

    await expect(getAnonymousEmptyCart()).resolves.toBe(EMPTY);
    expect(storeFetch).toHaveBeenCalledTimes(2);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({ event: "cart.empty.stale" }),
      expect.any(String),
    );
  });

  it("sin copia, propaga el error actual (no inventa un carrito)", async () => {
    const failure = new WooCommerceError("Store API caída", 503, "wc_store_network");
    vi.mocked(storeFetch).mockRejectedValueOnce(failure);

    await expect(getAnonymousEmptyCart()).rejects.toBe(failure);
  });

  it("tras un fallo sin copia, la siguiente llamada vuelve a intentarlo", async () => {
    vi.mocked(storeFetch).mockRejectedValueOnce(
      new WooCommerceError("Store API caída", 503),
    );
    await expect(getAnonymousEmptyCart()).rejects.toBeInstanceOf(WooCommerceError);

    await expect(getAnonymousEmptyCart()).resolves.toBe(EMPTY);
    expect(storeFetch).toHaveBeenCalledTimes(2);
  });

  it("un carrito con líneas NO se cachea ni se sirve (podría ser de otra persona)", async () => {
    vi.mocked(storeFetch).mockResolvedValueOnce({
      data: {
        items: [{ key: "k1", id: 1 }],
        items_count: 1,
        totals: { total_price: "0" },
      },
      cartToken: "token-de-sesion",
    } as never);

    await expect(getAnonymousEmptyCart()).rejects.toMatchObject({
      status: 502,
      code: "wc_store_invalid_cart",
    });

    // La siguiente lectura buena sí se sirve: el rechazo no dejó copia.
    await expect(getAnonymousEmptyCart()).resolves.toBe(EMPTY);
  });

  it("una respuesta con forma de lista tampoco se sirve", async () => {
    vi.mocked(storeFetch).mockResolvedValueOnce({
      data: [],
      cartToken: "token-de-sesion",
    } as never);

    await expect(getAnonymousEmptyCart()).rejects.toMatchObject({
      code: "wc_store_invalid_cart",
    });
  });
});
