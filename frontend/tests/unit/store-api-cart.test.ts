import { afterEach, describe, expect, it, vi } from "vitest";
import { cartApi } from "@/lib/client/store-api";
import { csrfFetch } from "@/lib/client/csrf";

// csrfFetch lee `document.cookie`, que no existe en el entorno `node` de estos tests.
vi.mock("@/lib/client/csrf", () => ({ csrfFetch: vi.fn() }));

// ============================================================================
//  El cliente del carrito (navegador → BFF) no debe fiarse de la forma de la
//  respuesta. Regresión: `parseCart` hacía `res.json() as StoreCart` sin
//  comprobar nada, así que un 200 con un cuerpo que no era un carrito (p. ej.
//  `{"data":{}}`) dejaba `cart = {data:{}}` en el contexto y el cajón del
//  carrito reventaba al leer `cart.totals.total_price`, mostrando la pantalla
//  de error 500 de toda la página. Era intermitente en el e2e de CI (carrera
//  entre ese fetch y el escaneo de accesibilidad).
// ============================================================================

const VALID_CART = {
  items: [],
  items_count: 0,
  needs_payment: false,
  needs_shipping: false,
  coupons: [],
  shipping_rates: [],
  totals: { total_price: "0", currency_code: "EUR", currency_minor_unit: 2 },
};

function stubFetch(body: unknown, init: { ok?: boolean; status?: number } = {}) {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => ({
      ok: init.ok ?? true,
      status: init.status ?? 200,
      json: async () => body,
    })) as unknown as typeof fetch,
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("cartApi.get — validación de la respuesta", () => {
  it("devuelve el carrito cuando la respuesta tiene su forma", async () => {
    stubFetch(VALID_CART);

    await expect(cartApi.get()).resolves.toEqual(VALID_CART);
  });

  it.each([
    ["un objeto sin carrito", { data: {} }],
    ["un objeto vacío", {}],
    ["null", null],
    ["un array", []],
    ["un texto", "ok"],
    ["sin totals", { ...VALID_CART, totals: undefined }],
    ["totals que no es un objeto", { ...VALID_CART, totals: "0" }],
    ["items que no es una lista", { ...VALID_CART, items: {} }],
    ["items_count que no es un número", { ...VALID_CART, items_count: "0" }],
  ])("rechaza %s en lugar de entregarlo como carrito", async (_label, body) => {
    stubFetch(body);

    await expect(cartApi.get()).rejects.toThrow(/carrito/i);
  });

  it("un error HTTP conserva el mensaje que manda el BFF", async () => {
    stubFetch({ error: "Sin conexión con la tienda" }, { ok: false, status: 502 });

    await expect(cartApi.get()).rejects.toThrow("Sin conexión con la tienda");
  });

  it("un error HTTP sin cuerpo útil usa el código de estado", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => ({
        ok: false,
        status: 500,
        json: async () => {
          throw new SyntaxError("Unexpected end of JSON input");
        },
      })) as unknown as typeof fetch,
    );

    await expect(cartApi.get()).rejects.toThrow("Error 500");
  });
});

describe("las mutaciones del carrito aplican la misma validación", () => {
  const response = (body: unknown) =>
    ({ ok: true, status: 200, json: async () => body }) as Response;

  it("una respuesta que no es un carrito se rechaza al vaciar el carrito", async () => {
    vi.mocked(csrfFetch).mockResolvedValueOnce(response({ data: {} }));

    await expect(cartApi.clear()).rejects.toThrow(/carrito/i);
  });

  it("una respuesta válida se entrega tal cual al añadir un producto", async () => {
    vi.mocked(csrfFetch).mockResolvedValueOnce(response(VALID_CART));

    await expect(cartApi.addItem(1)).resolves.toEqual(VALID_CART);
  });
});
