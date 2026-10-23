import { describe, expect, it } from "vitest";
import {
  ADDRESSES_META_KEY,
  mergeAddressLocation,
  parseSavedAddresses,
} from "./addresses";
import type { SavedAddress } from "@/types/address";

const ADDRESS = { address_1: "Calle 1", city: "Madrid", is_default: true };

function saved(overrides: Partial<SavedAddress> = {}): SavedAddress {
  return {
    first_name: "",
    last_name: "",
    company: "",
    address_1: "Calle 72 # 10-34",
    address_2: "",
    city: "Bogotá",
    state: "CO-DC",
    postcode: "",
    country: "CO",
    phone: "300 123 4567",
    is_default: false,
    label: "",
    ...overrides,
  };
}

describe("parseSavedAddresses", () => {
  it("lee la libreta del meta del cliente (JSON en texto)", () => {
    expect(
      parseSavedAddresses([
        { key: ADDRESSES_META_KEY, value: JSON.stringify([ADDRESS]) },
      ]),
    ).toEqual([ADDRESS]);
  });

  it("acepta el valor ya parseado", () => {
    expect(
      parseSavedAddresses([{ key: ADDRESSES_META_KEY, value: [ADDRESS] as never }]),
    ).toEqual([ADDRESS]);
  });

  it.each([
    ["sin meta", undefined],
    ["sin la clave", [{ key: "otro", value: "x" }]],
    ["JSON roto", [{ key: ADDRESSES_META_KEY, value: "{no es json" }]],
    ["no es una lista", [{ key: ADDRESSES_META_KEY, value: '{"a":1}' }]],
  ])("%s → libreta vacía", (_name, meta) => {
    expect(parseSavedAddresses(meta as never)).toEqual([]);
  });
});

describe("mergeAddressLocation", () => {
  const pin = { lat: 4.6533, lng: -74.0836 };

  it("conserva la ubicación si solo cambia el teléfono o el nombre", () => {
    const previous = saved({ location: pin });
    const next = saved({ phone: "310 000 0000", first_name: "Ana" });

    expect(mergeAddressLocation(previous, next).location).toEqual(pin);
  });

  it("borra la ubicación si cambia la calle (misma ciudad)", () => {
    const previous = saved({ location: pin });
    const next = saved({ address_1: "Carrera 7 # 20-10" });

    expect(mergeAddressLocation(previous, next)).not.toHaveProperty("location");
  });

  it("borra la ubicación si cambia la ciudad (misma calle)", () => {
    const previous = saved({ location: pin });
    const next = saved({ city: "Cali" });

    expect(mergeAddressLocation(previous, next)).not.toHaveProperty("location");
  });

  it("un cambio solo de mayúsculas, tildes o espacios no cuenta como cambio (igual que WordPress)", () => {
    const previous = saved({ location: pin });
    const next = saved({ address_1: "  calle  72 # 10-34 ", city: "BOGOTA" });

    expect(mergeAddressLocation(previous, next).location).toEqual(pin);
  });

  it("una ubicación nueva que llega en la edición se usa tal cual", () => {
    const previous = saved();
    const next = saved({ location: { lat: 6.2442, lng: -75.5812 } });

    expect(mergeAddressLocation(previous, next).location).toEqual({
      lat: 6.2442,
      lng: -75.5812,
    });
  });

  it("sin dirección previa (alta) no hay ubicación que heredar", () => {
    const next = saved();

    expect(mergeAddressLocation(undefined, next)).not.toHaveProperty("location");
  });

  it("si la anterior no tenía ubicación, no aparece una", () => {
    const previous = saved();
    const next = saved();

    expect(mergeAddressLocation(previous, next)).not.toHaveProperty("location");
  });
});
