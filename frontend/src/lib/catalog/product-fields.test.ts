import { describe, expect, it } from "vitest";
import {
  labelledProductFields,
  productFieldBoolean,
  productFieldNumber,
  readProductFields,
} from "./product-fields";

describe("readProductFields", () => {
  it("convierte la lista de pares en un mapa", () => {
    expect(
      readProductFields([
        { key: "dosis", value: "8 gotas" },
        { key: "peso", value: "40" },
      ]),
    ).toEqual({ dosis: "8 gotas", peso: "40" });
  });

  it("descarta lo que no es un par válido y los valores vacíos", () => {
    expect(
      readProductFields([
        null,
        undefined,
        { key: "", value: "x" },
        { key: "vacio", value: "   " },
        { key: 3, value: "x" } as never,
        { key: "ok", value: "sí" },
      ]),
    ).toEqual({ ok: "sí" });
  });

  it("sin lista no hay campos", () => {
    expect(readProductFields(null)).toEqual({});
    expect(readProductFields(undefined)).toEqual({});
    expect(readProductFields([])).toEqual({});
  });

  it("ante una clave repetida manda la última", () => {
    expect(
      readProductFields([
        { key: "a", value: "1" },
        { key: "a", value: "2" },
      ]),
    ).toEqual({ a: "2" });
  });
});

describe("lectores tipados", () => {
  const fields = readProductFields([
    { key: "peso", value: "40.5" },
    { key: "texto", value: "hola" },
    { key: "activo", value: "1" },
  ]);

  it("productFieldNumber solo devuelve números", () => {
    expect(productFieldNumber(fields, "peso")).toBe(40.5);
    expect(productFieldNumber(fields, "texto")).toBeNull();
    expect(productFieldNumber(fields, "ausente")).toBeNull();
  });

  it("productFieldBoolean solo es true con «1»", () => {
    expect(productFieldBoolean(fields, "activo")).toBe(true);
    expect(productFieldBoolean(fields, "peso")).toBe(false);
    expect(productFieldBoolean(fields, "ausente")).toBe(false);
  });
});

describe("labelledProductFields", () => {
  const fields = readProductFields([
    { key: "peso", value: "40" },
    { key: "sin_etiqueta", value: "x" },
    { key: "no_declarado", value: "y" },
  ]);

  it("lista los campos con valor y etiqueta, en el orden de la declaración", () => {
    const list = labelledProductFields(fields, {
      dosis: { type: "text", label: "Modo de uso" },
      peso: { type: "number", label: "Peso" },
      sin_etiqueta: { type: "text" },
    });

    expect(list).toEqual([{ key: "peso", label: "Peso", type: "number", value: "40" }]);
  });

  it("sin declaración no hay lista", () => {
    expect(labelledProductFields(fields, undefined)).toEqual([]);
  });
});
