// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { loyaltyApi, type LoyaltySummaryResponse } from "./loyalty";

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });

const CART = {
  items: [],
  items_count: 0,
  needs_payment: true,
  needs_shipping: false,
  coupons: [],
  shipping_rates: [],
  totals: { total_price: "0" },
};

function fetchMock() {
  const mock = vi.fn();
  vi.stubGlobal("fetch", mock);
  return mock;
}

beforeEach(() => {
  document.cookie = "hwe_csrf=token-123; path=/";
  document.documentElement.lang = "en";
});

afterEach(() => {
  vi.unstubAllGlobals();
  document.documentElement.lang = "";
});

describe("loyaltyApi (lecturas)", () => {
  it("pide el resumen al BFF con el idioma de la página", async () => {
    const summary: LoyaltySummaryResponse = {
      participant: true,
      balance: 120,
      balance_value: 60,
      earned: 200,
      used: 80,
      expiring: { points: 0, date: null },
    };
    const fetch = fetchMock().mockResolvedValue(json(summary));

    await expect(loyaltyApi.summary()).resolves.toEqual(summary);
    expect(fetch).toHaveBeenCalledWith("/api/loyalty/summary", {
      credentials: "same-origin",
      headers: { "X-HWE-Locale": "en" },
    });
  });

  it("pagina los movimientos", async () => {
    const fetch = fetchMock().mockResolvedValue(
      json({ items: [], total: 0, pages: 0 }),
    );

    await loyaltyApi.transactions({ page: 2, perPage: 50 });

    expect(fetch).toHaveBeenCalledWith(
      "/api/loyalty/transactions?page=2&per_page=50",
      expect.objectContaining({ credentials: "same-origin" }),
    );
  });

  it("pide la red del nivel indicado", async () => {
    const fetch = fetchMock().mockResolvedValue(
      json({
        items: [],
        total: 0,
        pages: 0,
        totals: { level1: 0, level2: 0, pending: 0, earned: 0 },
      }),
    );

    await loyaltyApi.referrals({ level: 2, page: 1 });

    expect(fetch).toHaveBeenCalledWith(
      "/api/loyalty/referrals?level=2&page=1",
      expect.objectContaining({ credentials: "same-origin" }),
    );
  });

  it("lee el enlace de referido", async () => {
    const fetch = fetchMock().mockResolvedValue(
      json({ code: "ABC12345", url: "https://shop.example/?ref=ABC12345" }),
    );

    await expect(loyaltyApi.referralLink()).resolves.toEqual({
      code: "ABC12345",
      url: "https://shop.example/?ref=ABC12345",
    });
    expect(fetch).toHaveBeenCalledWith("/api/loyalty/referral-link", {
      credentials: "same-origin",
      headers: { "X-HWE-Locale": "en" },
    });
  });

  it("valida un código", async () => {
    const fetch = fetchMock().mockResolvedValue(json({ valid: true }));

    await expect(loyaltyApi.validateCode("ABC 123")).resolves.toEqual({ valid: true });
    expect(fetch).toHaveBeenCalledWith(
      "/api/loyalty/validate-code?code=ABC+123",
      expect.objectContaining({ credentials: "same-origin" }),
    );
  });

  it("una lectura fallida lanza con el mensaje del BFF", async () => {
    fetchMock().mockResolvedValue(json({ error: "No autenticado." }, 401));

    await expect(loyaltyApi.summary()).rejects.toThrow("No autenticado.");
  });
});

describe("loyaltyApi.transfer", () => {
  it("envía la clave de idempotencia y el token CSRF", async () => {
    const fetch = fetchMock().mockResolvedValue(
      json({ ok: true, sent: 100, received: 95, fee: 5, recipient_name: "Ana" }),
    );

    await expect(
      loyaltyApi.transfer({
        code: "ABC123",
        points: 100,
        note: "Gracias",
        idempotencyKey: "idem-1",
      }),
    ).resolves.toEqual({
      ok: true,
      sent: 100,
      received: 95,
      fee: 5,
      recipient_name: "Ana",
    });

    const [url, init] = fetch.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/loyalty/transfer");
    expect(init.method).toBe("POST");
    expect(init.headers).toMatchObject({
      "Content-Type": "application/json",
      "X-CSRF-Token": "token-123",
      "Idempotency-Key": "idem-1",
    });
    expect(JSON.parse(String(init.body))).toEqual({
      code: "ABC123",
      points: 100,
      note: "Gracias",
    });
  });

  it("genera una clave de idempotencia si no se la dan", async () => {
    const fetch = fetchMock().mockResolvedValue(
      json({ ok: true, sent: 10, received: 10, fee: 0, recipient_name: "Ana" }),
    );

    await loyaltyApi.transfer({ code: "ABC123", points: 10 });

    const init = fetch.mock.calls[0][1] as RequestInit;
    expect(init.headers).toMatchObject({
      "Idempotency-Key": expect.stringMatching(/^[0-9a-f-]{36}$/),
    });
  });

  it("devuelve el código de error estable del BFF sin lanzar", async () => {
    const fetch = fetchMock().mockResolvedValue(
      json({ ok: false, error: "insufficient" }, 422),
    );

    await expect(
      loyaltyApi.transfer({ code: "ABC123", points: 100, idempotencyKey: "idem-1" }),
    ).resolves.toEqual({ ok: false, error: "insufficient" });
    expect(fetch).toHaveBeenCalled();
  });

  it("un error desconocido se normaliza a `failed`", async () => {
    fetchMock().mockResolvedValue(json({ error: "otro" }, 500));

    await expect(
      loyaltyApi.transfer({ code: "ABC123", points: 100, idempotencyKey: "idem-1" }),
    ).resolves.toEqual({ ok: false, error: "failed" });
  });

  it("un fallo de red se devuelve como `failed`", async () => {
    fetchMock().mockRejectedValue(new Error("offline"));

    await expect(
      loyaltyApi.transfer({ code: "ABC123", points: 100, idempotencyKey: "idem-1" }),
    ).resolves.toEqual({ ok: false, error: "failed" });
  });
});

describe("loyaltyApi (canje en el carrito)", () => {
  it("aplica los puntos con CSRF y devuelve el carrito", async () => {
    const fetch = fetchMock().mockResolvedValue(json(CART));

    await expect(loyaltyApi.applyPoints(100)).resolves.toEqual(CART);

    const [url, init] = fetch.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/store/cart/points");
    expect(init.method).toBe("POST");
    expect(init.headers).toMatchObject({ "X-CSRF-Token": "token-123" });
    expect(JSON.parse(String(init.body))).toEqual({ points: 100 });
  });

  it("quita el canje enviando 0", async () => {
    const fetch = fetchMock().mockResolvedValue(json(CART));

    await expect(loyaltyApi.clearPoints()).resolves.toEqual(CART);

    const [url, init] = fetch.mock.calls[0] as [string, RequestInit];
    expect(url).toBe("/api/store/cart/points");
    expect(init.method).toBe("DELETE");
    expect(JSON.parse(String(init.body))).toEqual({ points: 0 });
  });

  it("una respuesta que no es un carrito no se da por buena", async () => {
    fetchMock().mockResolvedValue(json({ ok: true }));

    await expect(loyaltyApi.applyPoints(100)).rejects.toThrow(
      "La respuesta del carrito no es válida.",
    );
  });
});
