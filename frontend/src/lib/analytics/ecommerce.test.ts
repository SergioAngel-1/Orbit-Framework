import { describe, expect, it } from "vitest";
import type { StoreCart, StoreCartItem } from "@/types/woocommerce";
import {
  addToCartParams,
  beginCheckoutParams,
  metaEvent,
  purchaseParams,
  storeAmountToMajor,
  tiktokParams,
  viewItemParams,
  type EcommerceParams,
} from "./ecommerce";

function item(
  id: number,
  name: string,
  price: string,
  quantity: number,
): StoreCartItem {
  return {
    key: `k${id}`,
    id,
    quantity,
    name,
    short_description: "",
    permalink: "",
    images: [],
    prices: {
      price,
      regular_price: price,
      sale_price: price,
      currency_code: "EUR",
      currency_minor_unit: 0,
    },
    totals: { line_subtotal: "0", line_total: "0" },
  };
}

function cart(items: StoreCartItem[], totalPrice: string): StoreCart {
  return {
    items,
    items_count: items.reduce((n, i) => n + i.quantity, 0),
    needs_payment: true,
    needs_shipping: true,
    coupons: [],
    shipping_rates: [],
    totals: {
      total_items: "0",
      total_items_tax: "0",
      total_fees: "0",
      total_fees_tax: "0",
      total_discount: "0",
      total_discount_tax: "0",
      total_shipping: "0",
      total_shipping_tax: "0",
      total_price: totalPrice,
      total_tax: "0",
      tax_lines: [],
      currency_code: "EUR",
      currency_symbol: "$",
      currency_minor_unit: 0,
      currency_decimal_separator: ",",
      currency_thousand_separator: ".",
      currency_prefix: "$",
      currency_suffix: "",
    },
  };
}

describe("storeAmountToMajor", () => {
  it("divide por la unidad menor de la moneda", () => {
    expect(storeAmountToMajor("4500", 2)).toBe(45);
    expect(storeAmountToMajor("45000", 0)).toBe(45000);
  });

  it("un importe que no es número vale 0", () => {
    expect(storeAmountToMajor("n/a", 0)).toBe(0);
  });
});

describe("addToCartParams", () => {
  it("valora solo lo añadido, no la línea entera", () => {
    const result = cart([item(7, "Camiseta", "45000", 3)], "135000");
    expect(addToCartParams(result, 7, 1)).toEqual({
      currency: "EUR",
      value: 45000,
      items: [{ item_id: "7", item_name: "Camiseta", price: 45000, quantity: 1 }],
    });
  });

  it("sin la línea en el carrito no hay evento", () => {
    expect(addToCartParams(cart([], "0"), 7, 1)).toBeNull();
  });
});

describe("beginCheckoutParams", () => {
  it("el carrito entero, valorado por su total", () => {
    const params = beginCheckoutParams(
      cart([item(1, "A", "10000", 2), item(2, "B", "5000", 1)], "32000"),
    );
    expect(params?.value).toBe(32000);
    expect(params?.items.map((i) => [i.item_id, i.quantity])).toEqual([
      ["1", 2],
      ["2", 1],
    ]);
  });

  it("carrito vacío: sin evento", () => {
    expect(beginCheckoutParams(cart([], "0"))).toBeNull();
  });
});

describe("purchaseParams", () => {
  it("lleva transaction_id y value (lo que GA4 necesita para ingresos y deduplicar)", () => {
    expect(
      purchaseParams(981, "90000.00", "EUR", [
        { product_id: 7, name: "Camiseta", price: 45000, quantity: 2 },
      ]),
    ).toEqual({
      transaction_id: "981",
      currency: "EUR",
      value: 90000,
      items: [{ item_id: "7", item_name: "Camiseta", price: 45000, quantity: 2 }],
    });
  });

  it("en una variación, el id es el de la variación", () => {
    const params = purchaseParams(1, "10", "EUR", [
      { product_id: 7, variation_id: 70, name: "A", price: 10, quantity: 1 },
      { product_id: 8, variation_id: 0, name: "B", price: 0, quantity: 1 },
    ]);
    expect(params?.items.map((i) => i.item_id)).toEqual(["70", "8"]);
  });

  it("sin total numérico no hay evento (mejor ninguno que uno con ingresos 0)", () => {
    expect(purchaseParams(1, null, "EUR", [])).toBeNull();
    expect(purchaseParams(1, "", "EUR", [])).toBeNull();
    expect(purchaseParams(1, "abc", "EUR", [])).toBeNull();
  });
});

describe("tiktokParams", () => {
  it("traduce items a contents, sin el nombre del producto", () => {
    expect(
      tiktokParams({
        transaction_id: "5",
        currency: "EUR",
        value: 10,
        items: [{ item_id: "1", item_name: "A", price: 10, quantity: 1 }],
      }),
    ).toEqual({
      value: 10,
      currency: "EUR",
      content_type: "product",
      contents: [{ content_id: "1", price: 10, quantity: 1 }],
      order_id: "5",
    });
  });
});

describe("viewItemParams", () => {
  it("el producto de la ficha, valorado por su precio publicado (formateado)", () => {
    expect(
      viewItemParams({ databaseId: 7, name: "Camiseta", price: "&#36;25.300" }, "EUR"),
    ).toEqual({
      currency: "EUR",
      value: 25300,
      items: [{ item_id: "7", item_name: "Camiseta", price: 25300, quantity: 1 }],
    });
  });

  it("sin precio cargado mide la visita con valor 0", () => {
    expect(viewItemParams({ databaseId: 7, name: "X", price: null }, "EUR").value).toBe(
      0,
    );
  });
});

describe("metaEvent", () => {
  const item = (id: string, price: number, quantity: number, category?: string) => ({
    item_id: id,
    item_name: "Camiseta secreta",
    price,
    quantity,
    ...(category ? { item_category: category } : {}),
  });

  it("ViewContent: ids, tipo, línea, valor y moneda — sin nombre de producto", () => {
    const event = metaEvent("view_item", {
      currency: "EUR",
      value: 25300,
      items: [item("7", 25300, 1, "ropa")],
    });
    expect(event).toEqual({
      name: "ViewContent",
      params: {
        content_ids: ["7"],
        content_type: "product",
        content_category: "ropa",
        value: 25300,
        currency: "EUR",
      },
    });
    expect(JSON.stringify(event)).not.toContain("Camiseta secreta");
  });

  it("AddToCart: lo mismo + contents, con value = precio × cantidad", () => {
    const params = addToCartParams(cart([item7()], "0"), 7, 2) as EcommerceParams;
    const event = metaEvent("add_to_cart", params);
    expect(event.name).toBe("AddToCart");
    expect(event.params).toEqual({
      content_ids: ["7"],
      content_type: "product",
      value: 90000,
      currency: "EUR",
      contents: [{ id: "7", quantity: 2, item_price: 45000 }],
    });
  });

  it("sin categoría no inventa content_category", () => {
    const event = metaEvent("view_item", {
      currency: "EUR",
      value: 1,
      items: [item("7", 1, 1)],
    });
    expect(event.params).not.toHaveProperty("content_category");
  });

  it("InitiateCheckout: contents y num_items, valorado por el total del carrito", () => {
    const event = metaEvent("begin_checkout", {
      currency: "EUR",
      value: 32000,
      items: [item("1", 10000, 2), item("2", 5000, 1)],
    });
    expect(event).toEqual({
      name: "InitiateCheckout",
      params: {
        content_ids: ["1", "2"],
        content_type: "product",
        value: 32000,
        currency: "EUR",
        contents: [
          { id: "1", quantity: 2, item_price: 10000 },
          { id: "2", quantity: 1, item_price: 5000 },
        ],
        num_items: 3,
      },
    });
  });

  it("Purchase: order_id y el eventID de deduplicación `purchase_<pedido>`", () => {
    const event = metaEvent(
      "purchase",
      purchaseParams(1234, "95000", "EUR", [
        { product_id: 7, name: "Camiseta", price: 45000, quantity: 2 },
      ]) as EcommerceParams,
    );
    expect(event).toEqual({
      name: "Purchase",
      params: {
        content_ids: ["7"],
        content_type: "product",
        value: 95000,
        currency: "EUR",
        contents: [{ id: "7", quantity: 2, item_price: 45000 }],
        num_items: 2,
        order_id: "1234",
      },
      eventId: "purchase_1234",
    });
  });
});

describe("metaEvent · categoría", () => {
  const items = (...categories: Array<string | undefined>) =>
    categories.map((item_category, i) => ({
      item_id: String(i + 1),
      item_name: "x",
      price: 1,
      quantity: 1,
      ...(item_category ? { item_category } : {}),
    }));
  const purchase = (...categories: Array<string | undefined>) =>
    metaEvent("purchase", {
      transaction_id: "9",
      currency: "EUR",
      value: 3,
      items: items(...categories),
    });

  it("lleva content_category y conserva order_id y el eventID", () => {
    const event = purchase("ropa");
    expect(event.params).toMatchObject({ content_category: "ropa", order_id: "9" });
    expect(event.eventId).toBe("purchase_9");
  });

  it("un pedido con categorías distintas no inventa una: ninguna", () => {
    expect(purchase("ropa", "calzado").params).not.toHaveProperty("content_category");
    expect(purchase("ropa", undefined).params).not.toHaveProperty("content_category");
  });

  it("todas iguales: esa", () => {
    expect(purchase("ropa", "ropa").params.content_category).toBe("ropa");
  });
});

function item7(): StoreCartItem {
  return item(7, "Camiseta", "45000", 3);
}
