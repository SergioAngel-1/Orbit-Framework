import { describe, expect, it } from "vitest";
import {
  planReorder,
  reorderProductIds,
  reorderReasonFromError,
  type ReorderProduct,
} from "./reorder";
import type { WooOrderLineItem } from "@/types/woocommerce";

function line(overrides: Partial<WooOrderLineItem> = {}): WooOrderLineItem {
  return {
    id: 1,
    name: "Camiseta",
    product_id: 10,
    quantity: 2,
    price: 30000,
    total: "60000",
    subtotal: "60000",
    ...overrides,
  };
}

function product(overrides: Partial<ReorderProduct> = {}): ReorderProduct {
  return {
    id: 10,
    status: "publish",
    purchasable: true,
    stock_status: "instock",
    manage_stock: false,
    stock_quantity: null,
    backorders_allowed: false,
    ...overrides,
  };
}

describe("planReorder", () => {
  it("añade la línea con su cantidad si el producto se vende y hay stock", () => {
    expect(planReorder([line()], [product()])).toEqual({
      add: [{ productId: 10, name: "Camiseta", quantity: 2 }],
      skipped: [],
    });
  });

  it("descarta lo que ya no existe, no está publicado o no se vende", () => {
    const plan = planReorder(
      [
        line({ product_id: 0, name: "Borrado" }),
        line({ product_id: 11, name: "Sin consultar" }),
        line({ product_id: 12, name: "Privado" }),
        line({ product_id: 13, name: "Sin precio" }),
      ],
      [product({ id: 12, status: "private" }), product({ id: 13, purchasable: false })],
    );
    expect(plan.add).toEqual([]);
    expect(plan.skipped).toEqual([
      { name: "Borrado", reason: "unavailable" },
      { name: "Sin consultar", reason: "unavailable" },
      { name: "Privado", reason: "unavailable" },
      { name: "Sin precio", reason: "unavailable" },
    ]);
  });

  it("descarta lo agotado, por estado o por inventario en cero", () => {
    const plan = planReorder(
      [line({ name: "A" }), line({ product_id: 11, name: "B" })],
      [
        product({ stock_status: "outofstock" }),
        product({ id: 11, manage_stock: true, stock_quantity: 0 }),
      ],
    );
    expect(plan.skipped).toEqual([
      { name: "A", reason: "out_of_stock" },
      { name: "B", reason: "out_of_stock" },
    ]);
  });

  it("recorta la cantidad al stock disponible y conserva la pedida", () => {
    const plan = planReorder(
      [line({ quantity: 5 })],
      [product({ manage_stock: true, stock_quantity: 3 })],
    );
    expect(plan.add).toEqual([
      { productId: 10, name: "Camiseta", quantity: 3, requested: 5 },
    ]);
  });

  it("con reservas permitidas no hay techo de stock", () => {
    const plan = planReorder(
      [line({ quantity: 5 })],
      [product({ manage_stock: true, stock_quantity: 1, backorders_allowed: true })],
    );
    expect(plan.add[0]?.quantity).toBe(5);
  });
});

describe("reorderProductIds", () => {
  it("deduplica y descarta productos borrados (id 0)", () => {
    expect(
      reorderProductIds([line(), line({ id: 2 }), line({ product_id: 0 })]),
    ).toEqual([10]);
  });
});

describe("reorderReasonFromError", () => {
  it.each([
    ["woocommerce_rest_product_out_of_stock", "out_of_stock"],
    ["woocommerce_rest_product_partially_out_of_stock", "out_of_stock"],
    ["woocommerce_rest_product_not_purchasable", "unavailable"],
    [undefined, "unavailable"],
  ] as const)("%s → %s", (code, reason) => {
    expect(reorderReasonFromError(code)).toBe(reason);
  });
});
