import { describe, expect, it } from "vitest";
import { pickLocalized } from "./pick-localized";

describe("pickLocalized", () => {
  it("en inglés usa la traducción cuando existe", () => {
    expect(pickLocalized("Hola", "Hello", "en")).toBe("Hello");
  });

  it("en inglés cae al español si la traducción está vacía o ausente", () => {
    expect(pickLocalized("Hola", "", "en")).toBe("Hola");
    expect(pickLocalized("Hola", null, "en")).toBe("Hola");
    expect(pickLocalized("Hola", undefined, "en")).toBe("Hola");
  });

  it("en español (y otros locales) siempre devuelve español", () => {
    expect(pickLocalized("Hola", "Hello", "es")).toBe("Hola");
    expect(pickLocalized("Hola", "Hello", "fr")).toBe("Hola");
  });

  it("sin valor en español devuelve null", () => {
    expect(pickLocalized(null, "Hello", "en")).toBe("Hello");
    expect(pickLocalized(null, null, "en")).toBeNull();
    expect(pickLocalized(undefined, undefined, "es")).toBeNull();
  });
});
