import { describe, it, expect } from "vitest";
import { formatPrice, formatStoreAmount, stripHtml, formatDate } from "@/lib/format";

describe("formatPrice", () => {
  it("decodifica entidades HTML y elimina etiquetas", () => {
    expect(formatPrice("&euro;19&nbsp;<span>99</span>")).toBe("€19 99");
  });
  it("devuelve cadena vacía para nulos", () => {
    expect(formatPrice(null)).toBe("");
    expect(formatPrice(undefined)).toBe("");
  });
});

describe("formatStoreAmount", () => {
  it("convierte unidades menores a mayores con la moneda", () => {
    expect(formatStoreAmount("5999", 2, "EUR")).toBe("59.99 EUR");
  });
  it("respeta minorUnit 0 (p. ej. COP)", () => {
    expect(formatStoreAmount("50000", 0, "COP")).toBe("50000 COP");
  });
  it("devuelve el original si no es numérico", () => {
    expect(formatStoreAmount("abc")).toBe("abc");
  });
});

describe("stripHtml", () => {
  it("quita etiquetas y normaliza entidades", () => {
    expect(stripHtml("<p>Hola&nbsp;&amp; adiós&hellip;</p>")).toBe("Hola & adiós…");
  });
});

describe("formatDate", () => {
  it("formatea según el locale", () => {
    const es = formatDate("2026-06-18T00:00:00.000Z", "es");
    const en = formatDate("2026-06-18T00:00:00.000Z", "en");
    expect(es).toMatch(/2026/);
    expect(en).toMatch(/2026/);
  });
  it("devuelve la entrada si la fecha es inválida", () => {
    expect(formatDate("no-es-fecha")).toBe("no-es-fecha");
  });
});
