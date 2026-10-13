import { describe, expect, it } from "vitest";
import { ADDRESSES_META_KEY, parseSavedAddresses } from "./addresses";

const ADDRESS = { address_1: "Calle 1", city: "Madrid", is_default: true };

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
