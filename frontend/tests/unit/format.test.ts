import { describe, it, expect } from "vitest";
import {
  formatMoney,
  formatPrice,
  formatStoreAmount,
  parseMoneyAmount,
  stripHtml,
  formatDate,
} from "@/lib/format";

describe("parseMoneyAmount", () => {
  it("extrae números de HTML de Woo y de miles con punto", () => {
    expect(parseMoneyAmount("&euro;59,99")).toBe(59.99);
    expect(parseMoneyAmount("58.000")).toBe(58000);
    expect(parseMoneyAmount("$58.000,50")).toBe(58000.5);
  });
  it("devuelve null sin dígitos", () => {
    expect(parseMoneyAmount("")).toBeNull();
    expect(parseMoneyAmount(null)).toBeNull();
    expect(parseMoneyAmount("abc")).toBeNull();
  });
});

// Intl separa símbolo e importe con espacios duros (U+00A0/U+202F): se normalizan.
const plain = (v: string) => v.replace(/[\u00a0\u202f]/g, " ");

describe("formatMoney", () => {
  it("formatea con Intl en el idioma de la página", () => {
    expect(plain(formatMoney(59.99, "EUR", "es"))).toBe("59,99 €");
    expect(plain(formatMoney(59.99, "EUR", "en"))).toBe("€59.99");
  });
  it("usa los decimales de la tienda cuando se le dan", () => {
    expect(plain(formatMoney(50000, "COP", "es-CO", 0))).toBe("$ 50.000");
    expect(plain(formatMoney(50000.6, "cop", "es-CO", 0))).toBe("$ 50.001");
    expect(plain(formatMoney(12, "USD", "en", 0))).toBe("$12");
  });
  it("sin decimales usa los de Intl para la moneda", () => {
    expect(plain(formatMoney(12, "USD", "en"))).toBe("$12.00");
  });
  it("una moneda que Intl no conoce no rompe", () => {
    expect(formatMoney(5, "NOPE1", "es")).toBe("5.00 NOPE1");
  });
});

describe("formatPrice", () => {
  it("normaliza a la moneda, el locale y los decimales recibidos", () => {
    expect(plain(formatPrice("59.99", "EUR", "es"))).toBe("59,99 €");
    expect(plain(formatPrice(50000, "COP", "es-CO", 0))).toBe("$ 50.000");
  });
  it("devuelve cadena vacía para nulos", () => {
    expect(formatPrice(null, "EUR", "es")).toBe("");
    expect(formatPrice(undefined, "EUR", "es")).toBe("");
  });
  it("si no se puede parsear, devuelve el texto limpio", () => {
    expect(formatPrice("a consultar", "EUR", "es")).toBe("a consultar");
  });
});

describe("formatStoreAmount", () => {
  it("convierte unidades menores a mayores con la moneda y el locale", () => {
    expect(plain(formatStoreAmount("5999", 2, "EUR", "es"))).toBe("59,99 €");
    expect(plain(formatStoreAmount("5999", 2, "EUR", "en"))).toBe("€59.99");
  });
  it("minorUnit 0 se muestra sin decimales", () => {
    expect(plain(formatStoreAmount("50000", 0, "COP", "es-CO"))).toBe("$ 50.000");
  });
  it("devuelve el original si no es numérico", () => {
    expect(formatStoreAmount("abc", 2, "EUR", "es")).toBe("abc");
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
