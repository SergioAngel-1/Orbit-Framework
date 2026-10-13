import { describe, expect, it } from "vitest";
import { parseRawList } from "./validate-raw";

const isString = (item: unknown): item is string => typeof item === "string";

describe("parseRawList", () => {
  it("devuelve la lista cuando todos los elementos cumplen la guarda", () => {
    expect(parseRawList(["a", "b"], isString, "ns.key")).toEqual(["a", "b"]);
  });

  it("acepta la lista vacía", () => {
    expect(parseRawList([], isString, "ns.key")).toEqual([]);
  });

  it("falla con un mensaje legible si no es una lista", () => {
    expect(() => parseRawList("texto", isString, "ns.key")).toThrow(/"ns\.key"/);
    expect(() => parseRawList(null, isString, "ns.key")).toThrow(/array/);
  });

  it("falla indicando el índice del primer elemento inválido", () => {
    expect(() => parseRawList(["a", 2, "c"], isString, "ns.key")).toThrow(
      /"ns\.key"\[1\]/,
    );
  });
});
