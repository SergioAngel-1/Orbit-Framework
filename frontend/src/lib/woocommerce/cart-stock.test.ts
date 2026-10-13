import { describe, expect, it } from "vitest";
import { planCartStockAdjustments } from "./cart-stock";
import type { StoreCartItem } from "@/types/woocommerce";

const line = (
  key: string,
  quantity: number,
  limits?: { minimum?: number; maximum: number },
) =>
  ({
    key,
    quantity,
    quantity_limits: limits && {
      minimum: 1,
      multiple_of: 1,
      editable: true,
      ...limits,
    },
  }) as unknown as StoreCartItem;

describe("planCartStockAdjustments", () => {
  it("una línea dentro de los límites no se toca", () => {
    expect(planCartStockAdjustments([line("a", 2, { maximum: 5 })])).toEqual([]);
  });

  it("sin límites informados no inventa ajustes", () => {
    expect(planCartStockAdjustments([line("a", 2)])).toEqual([]);
  });

  it("sin existencias quita la línea", () => {
    expect(planCartStockAdjustments([line("a", 1, { maximum: 0 })])).toEqual([
      { action: "remove", key: "a", reason: "out_of_stock" },
    ]);
  });

  it("por encima del máximo la reduce al máximo", () => {
    expect(planCartStockAdjustments([line("a", 9, { maximum: 3 })])).toEqual([
      { action: "clamp", key: "a", quantity: 3, reason: "insufficient_stock" },
    ]);
  });

  it("por debajo del mínimo de compra la quita", () => {
    expect(
      planCartStockAdjustments([line("a", 1, { minimum: 2, maximum: 10 })]),
    ).toEqual([{ action: "remove", key: "a", reason: "below_minimum" }]);
  });
});
