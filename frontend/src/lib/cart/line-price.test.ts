import { describe, expect, it } from "vitest";
import { regularUnitAmount } from "./line-price";

const line = (price: string, regular: string, quantity = 1) => ({
  quantity,
  prices: { price, regular_price: regular },
});

describe("descuento de producto en una línea del carrito", () => {
  it("sin rebaja no hay precio regular que tachar", () => {
    expect(regularUnitAmount(line("4500000", "4500000"))).toBeNull();
  });

  it("con rebaja devuelve el precio regular de la unidad", () => {
    expect(regularUnitAmount(line("3600000", "4500000", 2))).toBe("4500000");
  });

  it("un importe ilegible no inventa una rebaja", () => {
    expect(regularUnitAmount(line("3600000", ""))).toBeNull();
    expect(regularUnitAmount(line("abc", "4500000"))).toBeNull();
  });
});
