import { describe, expect, it } from "vitest";
import { regularLineAmount, regularUnitAmount } from "./line-price";

const line = (price: string, regular: string, quantity = 1) => ({
  quantity,
  prices: { price, regular_price: regular },
});

describe("descuento de producto en una línea del carrito", () => {
  it("sin rebaja no hay precio regular que tachar", () => {
    expect(regularUnitAmount(line("4500000", "4500000"))).toBeNull();
    expect(regularLineAmount(line("4500000", "4500000", 3))).toBeNull();
  });

  it("con rebaja devuelve el regular, por unidad y por línea", () => {
    expect(regularUnitAmount(line("3600000", "4500000", 2))).toBe("4500000");
    expect(regularLineAmount(line("3600000", "4500000", 2))).toBe("9000000");
  });

  it("un importe ilegible no inventa una rebaja", () => {
    expect(regularUnitAmount(line("3600000", ""))).toBeNull();
    expect(regularUnitAmount(line("abc", "4500000"))).toBeNull();
  });
});
