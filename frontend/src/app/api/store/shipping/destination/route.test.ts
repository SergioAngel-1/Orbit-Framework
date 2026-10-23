import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { getSiteConfig } from "@/lib/config";
import { getGeoPack } from "@/lib/geo";
import { guardMutation } from "@/lib/api/guard";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { CO_PACK } from "../../../../../../tests/helpers/geo-pack";
import { PUT } from "./route";

// PUT /api/store/shipping/destination: fija la ciudad del carrito (módulo `shipping`).
// El estado se valida con el paquete de país: nunca se inventa una división.

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  writeCartToken: vi.fn(async () => undefined),
}));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  getCartRequestAuth: vi.fn(async () => ({
    cartToken: "ct",
    authToken: null,
    userId: null,
  })),
}));
vi.mock("@/lib/woocommerce/complete-cart", () => ({
  completeStoreCart: vi.fn(async (cart: unknown) => cart),
}));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/geo", () => ({ getGeoPack: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const put = (body: unknown) =>
  PUT(
    new Request("https://tienda.example/api/store/shipping/destination", {
      method: "PUT",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
    }),
  );

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSiteConfig).mockResolvedValue({ modules: { shipping: true } } as never);
  vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
  vi.mocked(storeFetch).mockResolvedValue({
    data: { items: [] },
    cartToken: "ct2",
  } as never);
});

describe("PUT /api/store/shipping/destination", () => {
  it("con el módulo apagado responde 404 y no toca el carrito", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({ modules: {} } as never);

    expect((await put({ city: "Pasto", country: "co", state: "Nariño" })).status).toBe(
      404,
    );
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("fija el destino con la división como código del paquete", async () => {
    const res = await put({
      city: "Pasto",
      country: "co",
      state: "Nariño",
      postcode: " 5200 ",
    });

    expect(res.status).toBe(200);
    expect(storeFetch).toHaveBeenCalledWith("/cart/update-customer", {
      method: "POST",
      body: {
        shipping_address: {
          city: "Pasto",
          country: "CO",
          state: "CO-NAR",
          postcode: "5200",
        },
      },
      cartToken: "ct",
      authToken: null,
    });
  });

  it("una ciudad conocida fija su división si no se indicó", async () => {
    await put({ city: "Bogotá", country: "CO" });

    expect(vi.mocked(storeFetch).mock.calls[0][1]).toMatchObject({
      body: { shipping_address: { state: "CO-DC" } },
    });
  });

  it("una división inválida o incoherente con la ciudad es un 422 y no toca el carrito", async () => {
    const unknown = await put({ city: "Pasto", country: "CO", state: "Atlantis" });
    expect(unknown.status).toBe(422);
    expect((await unknown.json()).details.state).toEqual(["Departamento no válido"]);

    expect(
      (await put({ city: "Bogotá", country: "CO", state: "Antioquia" })).status,
    ).toBe(422);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("valida la forma: ciudad obligatoria y país de dos letras", async () => {
    expect((await put({ country: "CO" })).status).toBe(422);
    expect((await put({ city: "Pasto", country: "COL" })).status).toBe(422);
  });
});

describe("pin del cliente (punto de entrega por la Store API)", () => {
  const STREET = "Calle 72 # 10-34";
  const PIN = { lat: 4.6533, lng: -74.0836 };
  const extensionCalls = () =>
    vi
      .mocked(storeFetch)
      .mock.calls.filter(([path]) => path === "/cart/extensions")
      .map(([, options]) => (options as { body: unknown }).body);

  it("con ubicación fija la calle en Woo y el punto con set_point, con los mismos datos", async () => {
    const res = await put({
      address_1: STREET,
      location: PIN,
      city: "Bogotá",
      country: "co",
      state: "Bogotá D.C.",
    });

    expect(res.status).toBe(200);
    expect(vi.mocked(storeFetch).mock.calls[0][0]).toBe("/cart/update-customer");
    expect(vi.mocked(storeFetch).mock.calls[0][1]).toMatchObject({
      body: {
        shipping_address: {
          address_1: STREET,
          city: "Bogotá",
          country: "CO",
          state: "CO-DC",
        },
      },
    });
    expect(extensionCalls()).toEqual([
      {
        namespace: "hwe_shipping",
        data: {
          action: "set_point",
          lat: PIN.lat,
          lng: PIN.lng,
          address_1: STREET,
          city: "Bogotá",
          country: "CO",
        },
      },
    ]);
  });

  it("sin `location` no toca el punto (ausente = conservar; WordPress lo invalida si cambia la calle)", async () => {
    const res = await put({ address_1: STREET, city: "Bogotá", country: "CO" });

    expect(res.status).toBe(200);
    expect(extensionCalls()).toEqual([]);
    // La respuesta es el carrito que devolvió `update-customer`, y su token se guarda.
    expect(await res.json()).toEqual({ items: [] });
    expect(writeCartToken).toHaveBeenCalledWith("ct2");
  });

  it("`location: null` borra el punto explícitamente con clear_point", async () => {
    const res = await put({
      address_1: STREET,
      location: null,
      city: "Bogotá",
      country: "CO",
    });

    expect(res.status).toBe(200);
    expect(extensionCalls()).toEqual([
      { namespace: "hwe_shipping", data: { action: "clear_point" } },
    ]);
  });

  it("sin calle no se envía address_1 a Woo y no hay punto que fijar", async () => {
    await put({ city: "Bogotá", country: "CO" });

    const body = vi.mocked(storeFetch).mock.calls[0][1] as {
      body: { shipping_address: Record<string, unknown> };
    };
    expect(body.body.shipping_address).not.toHaveProperty("address_1");
  });

  it("una ubicación sin calle es un 422 y no toca el carrito (el punto necesita la calle)", async () => {
    const res = await put({ location: PIN, city: "Bogotá", country: "CO" });

    expect(res.status).toBe(422);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it.each([
    ["latitud fuera de rango", { lat: 200, lng: -74 }],
    ["longitud como cadena", { lat: 4.6, lng: "-74" }],
    ["0,0", { lat: 0, lng: 0 }],
    ["coordenadas nulas", { lat: null, lng: null }],
  ])("una ubicación con %s es un 422 y no llega a WordPress", async (_, location) => {
    const res = await put({
      address_1: STREET,
      location,
      city: "Bogotá",
      country: "CO",
    });

    expect(res.status).toBe(422);
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("si el guard de escritura bloquea, no se toca WordPress", async () => {
    const blocked = new Response(null, { status: 403 });
    vi.mocked(guardMutation).mockResolvedValueOnce(blocked as never);

    const res = await put({
      address_1: STREET,
      location: PIN,
      city: "Bogotá",
      country: "CO",
    });

    expect(res).toBe(blocked);
    expect(storeFetch).not.toHaveBeenCalled();
  });
});
