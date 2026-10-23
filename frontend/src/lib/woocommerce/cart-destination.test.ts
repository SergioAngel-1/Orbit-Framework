import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { wcFetch } from "@/lib/woocommerce/client";
import { getSiteConfig } from "@/lib/config";
import { getGeoPack } from "@/lib/geo";
import { CO_PACK } from "../../../tests/helpers/geo-pack";
import type { StoreCart } from "@/types/woocommerce";
import type { SavedAddress } from "@/types/address";
import {
  ensureCartDestination,
  pushCartDestination,
  resetCartDestinationCache,
  syncCartDestination,
} from "./cart-destination";

// Destino de envío del carrito de un cliente con sesión: Woo no lee la libreta de la cuenta
// (`hwe_addresses`), así que el BFF le comunica la ciudad. Solo con el módulo `shipping`.

vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));
vi.mock("@/lib/woocommerce/client", () => ({ wcFetch: vi.fn() }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/geo", () => ({ getGeoPack: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), info: vi.fn(), error: vi.fn() },
}));

function address(overrides: Partial<SavedAddress> = {}): SavedAddress {
  return {
    first_name: "",
    last_name: "",
    company: "",
    address_1: "Calle 1",
    address_2: "",
    city: "Bogotá",
    state: "",
    postcode: "",
    country: "CO",
    phone: "",
    is_default: false,
    label: "",
    ...overrides,
  };
}

function cart(overrides: Partial<StoreCart> = {}): StoreCart {
  return {
    items: [],
    items_count: 1,
    needs_shipping: true,
    shipping_address: { city: "", country: "CO" },
    ...overrides,
  } as StoreCart;
}

const book = (addresses: SavedAddress[]) => ({
  meta_data: [{ key: "hwe_addresses", value: JSON.stringify(addresses) }],
});
const AUTH = { cartToken: "cart-token", authToken: "jwt" };

beforeEach(() => {
  vi.clearAllMocks();
  resetCartDestinationCache();
  vi.mocked(getSiteConfig).mockResolvedValue({ modules: { shipping: true } } as never);
  vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
  vi.mocked(storeFetch).mockResolvedValue({
    data: cart({ shipping_address: { city: "Bogotá", country: "CO" } }),
    cartToken: "cart-token-2",
  } as never);
});

describe("pushCartDestination", () => {
  it("comunica a Woo el destino de la dirección predeterminada, con la división como código", async () => {
    const result = await pushCartDestination(
      7,
      [address({ city: "Cali", state: "Nariño" }), address({ is_default: true })],
      AUTH,
    );

    expect(storeFetch).toHaveBeenCalledWith("/cart/update-customer", {
      method: "POST",
      body: {
        shipping_address: {
          address_1: "Calle 1",
          city: "Bogotá",
          country: "CO",
          state: "CO-DC",
          postcode: "",
        },
      },
      cartToken: "cart-token",
      authToken: "jwt",
    });
    expect(result?.cartToken).toBe("cart-token-2");
  });

  it("el pin del cliente no viaja a Woo: no es un campo de su dirección", async () => {
    await pushCartDestination(
      7,
      [address({ is_default: true, location: { lat: 4.6533, lng: -74.0836 } })],
      AUTH,
    );

    const [, options] = vi.mocked(storeFetch).mock.calls[0] as unknown as [
      string,
      { body: { shipping_address: Record<string, unknown> } },
    ];
    expect(options.body.shipping_address).not.toHaveProperty("location");
  });

  it("con pin en la dirección predeterminada, fija el punto con los mismos datos que la calle", async () => {
    await pushCartDestination(
      7,
      [address({ is_default: true, location: { lat: 4.6533, lng: -74.0836 } })],
      AUTH,
    );

    expect(storeFetch).toHaveBeenNthCalledWith(2, "/cart/extensions", {
      method: "POST",
      body: {
        namespace: "hwe_shipping",
        data: {
          action: "set_point",
          lat: 4.6533,
          lng: -74.0836,
          address_1: "Calle 1",
          city: "Bogotá",
          country: "CO",
        },
      },
      cartToken: "cart-token-2",
      authToken: "jwt",
    });
  });

  it("sin pin en la dirección predeterminada, borra el punto (no arrastra uno anterior)", async () => {
    await pushCartDestination(7, [address({ is_default: true })], AUTH);

    expect(storeFetch).toHaveBeenNthCalledWith(2, "/cart/extensions", {
      method: "POST",
      body: { namespace: "hwe_shipping", data: { action: "clear_point" } },
      cartToken: "cart-token-2",
      authToken: "jwt",
    });
  });

  it("con el módulo apagado no hace nada", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({ modules: {} } as never);

    expect(await pushCartDestination(7, [address()], AUTH)).toBeNull();
    expect(storeFetch).not.toHaveBeenCalled();
    expect(getGeoPack).not.toHaveBeenCalled();
  });

  it("una libreta sin ciudad utilizable no llama a Woo", async () => {
    expect(await pushCartDestination(7, [], AUTH)).toBeNull();
    expect(
      await pushCartDestination(
        7,
        [address({ city: "Pasto", state: "Atlantis" })],
        AUTH,
      ),
    ).toBeNull();
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("si WordPress falla, devuelve null (best-effort)", async () => {
    vi.mocked(storeFetch).mockRejectedValue(new Error("caído"));

    expect(await pushCartDestination(7, [address()], AUTH)).toBeNull();
  });
});

describe("syncCartDestination", () => {
  it("lee la libreta de la cuenta y empuja su destino", async () => {
    vi.mocked(wcFetch).mockResolvedValue(
      book([address({ is_default: true })]) as never,
    );

    await syncCartDestination(7, AUTH);

    expect(wcFetch).toHaveBeenCalledWith("/customers/7");
    expect(storeFetch).toHaveBeenCalledTimes(2);
    expect(vi.mocked(storeFetch).mock.calls[1][0]).toBe("/cart/extensions");
  });

  it("si no se puede leer la libreta, null y sin tocar el carrito", async () => {
    vi.mocked(wcFetch).mockRejectedValue(new Error("502"));

    expect(await syncCartDestination(7, AUTH)).toBeNull();
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("con el módulo apagado ni siquiera lee la libreta", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({ modules: {} } as never);

    expect(await syncCartDestination(7, AUTH)).toBeNull();
    expect(wcFetch).not.toHaveBeenCalled();
  });
});

describe("ensureCartDestination", () => {
  beforeEach(() => {
    vi.mocked(wcFetch).mockResolvedValue(
      book([address({ is_default: true })]) as never,
    );
  });

  it("recupera la ciudad de la libreta en un carrito con sesión que llega sin ella", async () => {
    const result = await ensureCartDestination(cart(), 7, AUTH);

    expect(result?.data.shipping_address?.city).toBe("Bogotá");
  });

  it.each([
    [
      "sin sesión",
      () => ensureCartDestination(cart(), null, { ...AUTH, authToken: null }),
    ],
    [
      "sin envío que calcular",
      () => ensureCartDestination(cart({ needs_shipping: false }), 7, AUTH),
    ],
    ["carrito vacío", () => ensureCartDestination(cart({ items_count: 0 }), 7, AUTH)],
    [
      "con ciudad ya fijada",
      () =>
        ensureCartDestination(
          cart({ shipping_address: { city: "Cali", country: "CO" } }),
          7,
          AUTH,
        ),
    ],
  ])("no hace nada %s", async (_name, run) => {
    expect(await run()).toBeNull();
    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("recuerda a los clientes sin dirección con ciudad y no repite la lectura", async () => {
    vi.mocked(wcFetch).mockResolvedValue(book([]) as never);

    await ensureCartDestination(cart(), 7, AUTH);
    await ensureCartDestination(cart(), 7, AUTH);

    expect(wcFetch).toHaveBeenCalledTimes(1);
  });

  it("guardar una dirección limpia ese recuerdo", async () => {
    vi.mocked(wcFetch).mockResolvedValue(book([]) as never);
    await ensureCartDestination(cart(), 7, AUTH);

    await pushCartDestination(7, [address()], AUTH);
    vi.mocked(wcFetch).mockResolvedValue(
      book([address({ is_default: true })]) as never,
    );

    expect(await ensureCartDestination(cart(), 7, AUTH)).not.toBeNull();
  });
});
