import { beforeEach, describe, expect, it, vi } from "vitest";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { syncDeliveryPoint } from "./delivery-point";

// Punto de entrega por la extensión `hwe_shipping`: ausente = no tocar, `null` = borrar,
// con pin y calle = fijar con la huella del destino.

vi.mock("@/lib/woocommerce/store-client", () => ({ storeFetch: vi.fn() }));

const AUTH = { cartToken: "ct", authToken: null };
const TARGET = { address_1: "Calle 1", city: "Bogotá", country: "CO" };
const PIN = { lat: 4.6533, lng: -74.0836 };
const sent = () =>
  vi
    .mocked(storeFetch)
    .mock.calls.map(
      ([path, options]) => [path, (options as { body: unknown }).body] as const,
    );

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(storeFetch).mockResolvedValue({
    data: { items: [] },
    cartToken: "ct2",
  } as never);
});

describe("syncDeliveryPoint", () => {
  it("sin `location` no llama a WordPress: el punto vigente se conserva", async () => {
    expect(await syncDeliveryPoint(TARGET, undefined, AUTH)).toBeNull();
    expect(storeFetch).not.toHaveBeenCalled();
  });

  it("con `location: null` borra el punto", async () => {
    await syncDeliveryPoint(TARGET, null, AUTH);

    expect(sent()).toEqual([
      [
        "/cart/extensions",
        { namespace: "hwe_shipping", data: { action: "clear_point" } },
      ],
    ]);
  });

  it("con pin y calle lo fija con la huella del destino", async () => {
    await syncDeliveryPoint(TARGET, PIN, AUTH);

    expect(sent()).toEqual([
      [
        "/cart/extensions",
        {
          namespace: "hwe_shipping",
          data: { action: "set_point", ...PIN, ...TARGET },
        },
      ],
    ]);
  });

  it("un pin sin calle no se puede huellar: no se envía nada (ni se borra el vigente)", async () => {
    expect(
      await syncDeliveryPoint({ ...TARGET, address_1: " " }, PIN, AUTH),
    ).toBeNull();
    expect(storeFetch).not.toHaveBeenCalled();
  });
});
