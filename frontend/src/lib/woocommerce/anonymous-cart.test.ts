import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { WooCommerceError } from "@/lib/woocommerce/client";
import { logger } from "@/lib/observability/logger";
import { getAnonymousEmptyCart, resetAnonymousCartCache } from "./anonymous-cart";

// Carrito vacío de un visitante sin cookie ni sesión: se lee UNA vez de la Store
// API (sin token), se cachea en memoria con TTL y single-flight, y nunca se
// guarda el token que devuelve esa lectura. Con la copia caducada se sirve al
// instante y se refresca en segundo plano; un fallo con copia aplaza el
// siguiente intento (backoff) para no encolar timeouts con WordPress caído.

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

const T0 = new Date("2026-01-01T00:00:00Z").getTime();
const TTL_MS = 5 * 60_000;
const BACKOFF_MS = 30_000;

/** Deja correr las microtareas del refresco en segundo plano. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i += 1) await Promise.resolve();
}

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
    vi.setSystemTime(T0);
    await getAnonymousEmptyCart();

    vi.setSystemTime(T0 + TTL_MS - 1000);
    await getAnonymousEmptyCart();
    expect(storeFetch).toHaveBeenCalledTimes(1);

    vi.setSystemTime(T0 + TTL_MS + 1000);
    await getAnonymousEmptyCart();
    await settle();
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

  it("con la copia caducada la sirve al instante y refresca en segundo plano", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(T0);
    await getAnonymousEmptyCart();

    // WordPress no responde (la lectura queda en vuelo): aun así la petición no
    // espera su timeout, devuelve la copia.
    let resolveRead!: (value: { data: unknown; cartToken: string }) => void;
    vi.mocked(storeFetch).mockReturnValueOnce(
      new Promise((resolve) => {
        resolveRead = resolve;
      }) as never,
    );
    vi.setSystemTime(T0 + TTL_MS + 1000);

    const cart = await getAnonymousEmptyCart();

    expect(cart).toBe(EMPTY);
    expect(storeFetch).toHaveBeenCalledTimes(2);

    resolveRead({ data: EMPTY, cartToken: "token-de-sesion" });
    await settle();
  });

  it("si WordPress falla y hay copia, sirve la copia", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(T0);
    await getAnonymousEmptyCart();

    vi.setSystemTime(T0 + TTL_MS + 1000);
    vi.mocked(storeFetch).mockRejectedValueOnce(
      new WooCommerceError("Store API caída", 503, "wc_store_network"),
    );

    await expect(getAnonymousEmptyCart()).resolves.toBe(EMPTY);
    await settle();

    expect(storeFetch).toHaveBeenCalledTimes(2);
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "cart.empty.stale",
        backoffMs: BACKOFF_MS,
      }),
      expect.any(String),
    );
  });

  it("con WP caído, N peticiones con la copia caducada no encolan lecturas: una por ventana de backoff", async () => {
    vi.useFakeTimers();
    vi.setSystemTime(T0);
    await getAnonymousEmptyCart();

    vi.setSystemTime(T0 + TTL_MS + 1000);
    vi.mocked(storeFetch).mockRejectedValueOnce(
      new WooCommerceError("Store API caída", 503, "wc_store_network"),
    );
    await getAnonymousEmptyCart();
    await settle();
    expect(storeFetch).toHaveBeenCalledTimes(2);

    // Diez visitantes más en el mismo instante: todos reciben la copia y ninguno
    // dispara otra lectura.
    for (let i = 0; i < 10; i += 1) {
      await expect(getAnonymousEmptyCart()).resolves.toBe(EMPTY);
    }
    await settle();
    expect(storeFetch).toHaveBeenCalledTimes(2);

    // Dentro de la ventana de backoff tampoco.
    vi.setSystemTime(T0 + TTL_MS + 1000 + BACKOFF_MS - 1000);
    await getAnonymousEmptyCart();
    await settle();
    expect(storeFetch).toHaveBeenCalledTimes(2);

    // Pasada la ventana, un solo reintento (que aquí vuelve a ir bien).
    vi.setSystemTime(T0 + TTL_MS + 1000 + BACKOFF_MS + 1000);
    await expect(getAnonymousEmptyCart()).resolves.toBe(EMPTY);
    await settle();
    expect(storeFetch).toHaveBeenCalledTimes(3);
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
