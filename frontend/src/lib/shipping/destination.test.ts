import { describe, expect, it } from "vitest";
import { CO_PACK, FALLBACK_ES_PACK } from "../../../tests/helpers/geo-pack";
import { GENERIC_PACK } from "@/lib/geo/generic";
import { cartHasDestination, destinationFromAddress } from "./destination";
import type { SavedAddress } from "@/types/address";

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

describe("destinationFromAddress", () => {
  it("traduce una dirección al destino de Woo, con la división como código del paquete", () => {
    expect(
      destinationFromAddress(CO_PACK, address({ city: " Bogotá ", country: "co" })),
    ).toEqual({
      address_1: "Calle 1",
      city: "Bogotá",
      country: "CO",
      state: "CO-DC",
      postcode: "",
    });
    expect(
      destinationFromAddress(
        CO_PACK,
        address({ city: "Pasto", state: "Nariño", postcode: " 5200 " }),
      ),
    ).toEqual({
      address_1: "Calle 1",
      city: "Pasto",
      country: "CO",
      state: "CO-NAR",
      postcode: "5200",
    });
  });

  it("incluye la calle (sin espacios sobrantes) y la ubicación si es válida", () => {
    const destino = destinationFromAddress(
      CO_PACK,
      address({
        address_1: "  Calle 72 # 10-34  ",
        city: "Bogotá",
        location: { lat: 4.6533, lng: -74.0836 },
      }),
    );

    expect(destino).toEqual({
      address_1: "Calle 72 # 10-34",
      city: "Bogotá",
      country: "CO",
      state: "CO-DC",
      postcode: "",
      location: { lat: 4.6533, lng: -74.0836 },
    });
  });

  it("una ubicación inválida no pasa al destino (0,0 o fuera de rango)", () => {
    for (const location of [
      { lat: 0, lng: 0 },
      { lat: 95, lng: -74 },
      { lat: NaN, lng: -74 },
    ]) {
      const destino = destinationFromAddress(
        CO_PACK,
        address({ city: "Bogotá", location }),
      );
      expect(destino).not.toBeNull();
      expect(destino).not.toHaveProperty("location");
    }
  });

  it("sin ciudad no hay destino", () => {
    expect(destinationFromAddress(CO_PACK, address({ city: " " }))).toBeNull();
    expect(destinationFromAddress(CO_PACK, null)).toBeNull();
    expect(destinationFromAddress(CO_PACK, undefined)).toBeNull();
  });

  it("nunca inventa una división: si el paquete no la reconoce, no hay destino", () => {
    expect(
      destinationFromAddress(CO_PACK, address({ city: "Pasto", state: "" })),
    ).toBeNull();
    expect(
      destinationFromAddress(CO_PACK, address({ city: "Pasto", state: "Atlantis" })),
    ).toBeNull();
  });

  it("sin país en la dirección usa el de la tienda; sin ninguno, no hay destino", () => {
    expect(
      destinationFromAddress(
        FALLBACK_ES_PACK,
        address({ city: "Madrid", country: "", state: "Madrid" }),
      ),
    ).toMatchObject({ country: "ES", state: "Madrid" });
    expect(destinationFromAddress(GENERIC_PACK, address({ country: "" }))).toBeNull();
  });

  it("una dirección de otro país es texto libre", () => {
    expect(
      destinationFromAddress(
        CO_PACK,
        address({ city: "Madrid", country: "ES", state: "Madrid" }),
      ),
    ).toEqual({
      address_1: "Calle 1",
      city: "Madrid",
      country: "ES",
      state: "Madrid",
      postcode: "",
    });
  });
});

describe("cartHasDestination", () => {
  const destination = {
    address_1: "Calle 1",
    city: "Bogotá",
    country: "CO",
    state: "CO-DC",
    postcode: "",
  };

  it("reconoce que el carrito ya tiene ese destino", () => {
    expect(
      cartHasDestination(
        {
          shipping_address: {
            address_1: " Calle 1",
            city: " Bogotá",
            country: "co",
            state: "CO-DC",
          },
        },
        destination,
      ),
    ).toBe(true);
  });

  it("no, si cambia la calle: el pin del cliente depende de ella (misma ciudad)", () => {
    expect(
      cartHasDestination(
        {
          shipping_address: {
            address_1: "Calle 2",
            city: "Bogotá",
            country: "CO",
            state: "CO-DC",
          },
        },
        destination,
      ),
    ).toBe(false);
  });

  it("sin calle en el destino (checkout) solo compara ciudad, país y división", () => {
    expect(
      cartHasDestination(
        {
          shipping_address: {
            address_1: "Otra calle",
            city: "Bogotá",
            country: "CO",
            state: "CO-DC",
          },
        },
        { city: "Bogotá", country: "CO", state: "CO-DC", postcode: "" },
      ),
    ).toBe(true);
  });

  it("no, si cambia la ciudad, el país o la división, o no hay carrito", () => {
    const base = {
      address_1: "Calle 1",
      city: "Bogotá",
      country: "CO",
      state: "CO-DC",
    };
    expect(
      cartHasDestination({ shipping_address: { ...base, city: "Cali" } }, destination),
    ).toBe(false);
    expect(
      cartHasDestination({ shipping_address: { ...base, country: "ES" } }, destination),
    ).toBe(false);
    expect(
      cartHasDestination(
        { shipping_address: { ...base, state: "CO-ANT" } },
        destination,
      ),
    ).toBe(false);
    expect(cartHasDestination(null, destination)).toBe(false);
    expect(cartHasDestination({}, destination)).toBe(false);
  });
});
