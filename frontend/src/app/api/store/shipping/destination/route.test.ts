import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { getSiteConfig } from "@/lib/config";
import { getGeoPack } from "@/lib/geo";
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
