import { describe, expect, it } from "vitest";
import { collectProductPurchase } from "./product-purchase";
import type { WooOrderLineItem } from "@/types/woocommerce";

const lines = (...ids: number[]) =>
  ids.map((product_id, i) => ({
    id: i + 1,
    name: "x",
    product_id,
    quantity: 1,
  })) as WooOrderLineItem[];

describe("collectProductPurchase", () => {
  it("se queda con el completado más reciente y sus productos", () => {
    expect(
      collectProductPurchase(
        [
          { id: 3, number: "3", status: "completed", line_items: lines(10, 11, 11) },
          { id: 2, number: "2", status: "completed", line_items: lines(10) },
        ],
        10,
      ),
    ).toEqual({
      completed: { id: 3, number: "3", productIds: [10, 11] },
      processing: null,
    });
  });

  it("un pedido en preparación más reciente no tapa un completado anterior", () => {
    const found = collectProductPurchase(
      [
        { id: 5, number: "5", status: "processing", line_items: lines(10) },
        { id: 4, number: "4", status: "completed", line_items: lines(10, 12) },
      ],
      10,
    );
    expect(found.completed?.id).toBe(4);
    expect(found.processing?.id).toBe(5);
  });

  it("solo en preparación: ofrece «Recibí mi producto» con ese pedido", () => {
    expect(
      collectProductPurchase(
        [{ id: 6, number: "TD-6", status: "processing", line_items: lines(10) }],
        10,
      ),
    ).toEqual({
      completed: null,
      processing: { id: 6, number: "TD-6", productIds: [10] },
    });
  });

  it("ignora pedidos sin el producto y acumula entre páginas", () => {
    const page1 = collectProductPurchase(
      [{ id: 9, number: "9", status: "processing", line_items: lines(10) }],
      10,
    );
    const page2 = collectProductPurchase(
      [
        { id: 8, number: "8", status: "completed", line_items: lines(99) },
        { id: 7, number: "7", status: "completed", line_items: lines(10) },
      ],
      10,
      page1,
    );
    expect(page2).toEqual({
      completed: { id: 7, number: "7", productIds: [10] },
      processing: { id: 9, number: "9", productIds: [10] },
    });
  });
});
