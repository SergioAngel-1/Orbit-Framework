import { describe, expect, it } from "vitest";
import { CO_PACK } from "../../../tests/helpers/geo-pack";
import { GENERIC_PACK } from "./generic";
import { normalizeGeoPack } from "./normalize";

describe("normalizeGeoPack", () => {
  it("acepta un paquete con la forma del contrato y el genérico de reserva", () => {
    expect(normalizeGeoPack(CO_PACK)).toEqual(CO_PACK);
    expect(normalizeGeoPack(GENERIC_PACK)).toEqual(GENERIC_PACK);
  });

  it.each([
    ["null", null],
    ["texto", "CO"],
    ["array", []],
    ["sin dirección", { ...CO_PACK, address: undefined }],
    ["país en minúsculas", { ...CO_PACK, country: "co" }],
    [
      "modo de división desconocido",
      { ...CO_PACK, address: { ...CO_PACK.address, division: "magic" } },
    ],
    ["prefijo sin +", { ...CO_PACK, dialCode: "57" }],
    ["división sin nombre", { ...CO_PACK, divisions: [{ code: "CO-X", name: "" }] }],
    [
      "documento sin patrón",
      { ...CO_PACK, documentTypes: [{ code: "CC", label: "CC", pattern: "" }] },
    ],
  ])("rechaza %s", (_name, raw) => {
    expect(normalizeGeoPack(raw)).toBeNull();
  });

  it("no deja pasar claves ajenas al contrato", () => {
    const parsed = normalizeGeoPack({ ...CO_PACK, internal: "secreto" });
    expect(parsed).not.toBeNull();
    expect(parsed).not.toHaveProperty("internal");
  });
});
