import { describe, it, expect } from "vitest";
import { normalizeShippingMethods } from "./normalize";

describe("normalizeShippingMethods", () => {
  it("mapea un payload válido", () => {
    const result = normalizeShippingMethods([
      { id: 1, name: "Empaque de regalo", added_value: 5000 },
    ]);
    expect(result).toEqual([{ id: 1, name: "Empaque de regalo", addedValue: 5000 }]);
  });

  it("descarta entradas sin id o nombre válidos", () => {
    const result = normalizeShippingMethods([
      { id: "x", name: "Malo", added_value: 0 },
      { id: 2, name: "", added_value: 0 },
      { id: 3, name: "Válido" },
    ]);
    expect(result).toEqual([{ id: 3, name: "Válido", addedValue: 0 }]);
  });

  it("devuelve [] si el payload no es un array", () => {
    expect(normalizeShippingMethods(null)).toEqual([]);
    expect(normalizeShippingMethods({})).toEqual([]);
  });

  it("trata added_value negativo o no numérico como 0", () => {
    const result = normalizeShippingMethods([
      { id: 4, name: "Raro", added_value: -5 },
      { id: 5, name: "Otro", added_value: "abc" },
    ]);
    expect(result).toEqual([
      { id: 4, name: "Raro", addedValue: 0 },
      { id: 5, name: "Otro", addedValue: 0 },
    ]);
  });
});
