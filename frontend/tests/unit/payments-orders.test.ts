import { describe, it, expect } from "vitest";
import { toMinorUnits, isOrderPaid, paymentMatchesOrder } from "@/lib/payments/orders";
import type { WooOrder } from "@/types/woocommerce";

function order(partial: Partial<WooOrder>): WooOrder {
  return {
    id: 1,
    status: "pending",
    currency: "COP",
    total: "59.99",
    customer_id: 1,
    date_created: "",
    billing: {},
    shipping: {},
    line_items: [],
    ...partial,
  };
}

describe("toMinorUnits", () => {
  it("convierte decimales a céntimos sin errores de coma flotante", () => {
    expect(toMinorUnits("59.99")).toBe(5999);
    expect(toMinorUnits("0.10")).toBe(10);
    expect(toMinorUnits("1000")).toBe(100000);
  });
});

describe("isOrderPaid", () => {
  it("reconoce estados pagados/cerrados", () => {
    expect(isOrderPaid(order({ status: "processing" }))).toBe(true);
    expect(isOrderPaid(order({ status: "completed" }))).toBe(true);
    expect(isOrderPaid(order({ status: "refunded" }))).toBe(true);
  });
  it("pending / on-hold no cuentan como pagados", () => {
    expect(isOrderPaid(order({ status: "pending" }))).toBe(false);
    expect(isOrderPaid(order({ status: "on-hold" }))).toBe(false);
  });
});

describe("paymentMatchesOrder", () => {
  const o = order({ total: "59.99", currency: "COP" });
  it("true cuando importe y moneda coinciden", () => {
    expect(paymentMatchesOrder(o, 5999, "COP")).toBe(true);
    expect(paymentMatchesOrder(o, 5999, "cop")).toBe(true); // case-insensitive
  });
  it("false si difiere el importe o la moneda", () => {
    expect(paymentMatchesOrder(o, 6000, "COP")).toBe(false);
    expect(paymentMatchesOrder(o, 5999, "USD")).toBe(false);
  });
  it("false si la pasarela no reporta importe/moneda (no marcar a ciegas)", () => {
    expect(paymentMatchesOrder(o, undefined, "COP")).toBe(false);
    expect(paymentMatchesOrder(o, 5999, undefined)).toBe(false);
  });
});
