import { describe, expect, it } from "vitest";
import {
  findAddressIndex,
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
    ).toMatchObject([ADDRESS]);
  });

  it("acepta el valor ya parseado", () => {
    expect(
      parseSavedAddresses([{ key: ADDRESSES_META_KEY, value: [ADDRESS] as never }]),
    ).toMatchObject([ADDRESS]);
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

describe("identificador de cada dirección", () => {
  const meta = (book: unknown[]) => [{ key: "hwe_addresses", value: JSON.stringify(book) }];
  const legacy = (street: string) => ({ address_1: street, city: "Lima", is_default: false });

  it("conserva el que ya está guardado", () => {
    const [address] = parseSavedAddresses(meta([{ ...legacy("A"), id: "addr_guardado" }]));

    expect(address!.id).toBe("addr_guardado");
  });

  it("una libreta anterior recibe ids estables entre lecturas, sin escribir nada", () => {
    const book = meta([legacy("A"), legacy("B")]);

    const first = parseSavedAddresses(book).map((a) => a.id);

    expect(first.every((id) => /^addr_[a-z0-9]+$/.test(id!))).toBe(true);
    expect(new Set(first).size).toBe(2);
    expect(parseSavedAddresses(book).map((a) => a.id)).toEqual(first);
  });

  it("el id derivado no depende de la posición", () => {
    const [a, b] = parseSavedAddresses(meta([legacy("A"), legacy("B")]));
    const [b2, a2] = parseSavedAddresses(meta([legacy("B"), legacy("A")]));

    expect(a2!.id).toBe(a!.id);
    expect(b2!.id).toBe(b!.id);
  });

  it("dos direcciones idénticas no comparten id", () => {
    const ids = parseSavedAddresses(meta([legacy("A"), legacy("A")])).map((a) => a.id);

    expect(new Set(ids).size).toBe(2);
  });

  it("un id guardado repetido o mal formado se sustituye", () => {
    const ids = parseSavedAddresses(
      meta([
        { ...legacy("A"), id: "repetido" },
        { ...legacy("B"), id: "repetido" },
        { ...legacy("C"), id: "<script>" },
      ]),
    ).map((a) => a.id);

    expect(ids[0]).toBe("repetido");
    expect(new Set(ids).size).toBe(3);
    expect(ids[2]).toMatch(/^addr_/);
  });

  it("no rompe con entradas que no son objetos", () => {
    expect(parseSavedAddresses(meta(["x", legacy("A")]))).toHaveLength(2);
  });
});

describe("findAddressIndex", () => {
  const book = [{ id: "a" }, { id: "b" }] as SavedAddress[];

  it("con `id` encuentra la dirección esté donde esté", () => {
    expect(findAddressIndex(book, { id: "b" })).toBe(1);
    expect(findAddressIndex(book, { id: "c" })).toBe(-1);
  });

  it("`id` manda sobre `index`", () => {
    expect(findAddressIndex(book, { id: "b", index: 0 })).toBe(1);
  });

  it("`index` sigue funcionando, dentro de rango", () => {
    expect(findAddressIndex(book, { index: 1 })).toBe(1);
    expect(findAddressIndex(book, { index: 2 })).toBe(-1);
    expect(findAddressIndex(book, {})).toBe(-1);
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
