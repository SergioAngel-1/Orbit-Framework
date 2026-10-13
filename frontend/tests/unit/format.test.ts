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

describe("formatMoney", () => {
  it("formatea con Intl según la moneda", () => {
    expect(formatMoney(59.99, "EUR")).toBe("59,99 €");
  });
  it("COP va sin decimales", () => {
    expect(formatMoney(50000, "COP")).toBe("$ 50.000");
    expect(formatMoney(50000.6, "cop")).toBe("$ 50.001");
  });
});

describe("formatPrice", () => {
  it("normaliza al currencyCode recibido", () => {
    expect(formatPrice("59.99", "EUR")).toBe("59,99 €");
    expect(formatPrice(50000, "COP")).toBe("$ 50.000");
  });
  it("devuelve cadena vacía para nulos", () => {
    expect(formatPrice(null, "EUR")).toBe("");
    expect(formatPrice(undefined, "EUR")).toBe("");
  });
  it("si no se puede parsear, devuelve el texto limpio", () => {
    expect(formatPrice("a consultar", "EUR")).toBe("a consultar");
  });
});

describe("formatStoreAmount", () => {
  it("convierte unidades menores a mayores con la moneda", () => {
    expect(formatStoreAmount("5999", 2, "EUR")).toBe("59,99 €");
  });
  it("respeta minorUnit 0 (p. ej. COP)", () => {
    expect(formatStoreAmount("50000", 0, "COP")).toBe("$ 50.000");
  });
  it("devuelve el original si no es numérico", () => {
    expect(formatStoreAmount("abc", 2, "EUR")).toBe("abc");
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
