import { describe, expect, it } from "vitest";
import {
  CASH_PAYMENT_METHOD,
  ONLINE_PAYMENT_METHOD,
  WHATSAPP_PAYMENT_METHOD,
} from "@/lib/payments/checkout-options";
import { countsAsPurchase, purchaseEventId } from "./purchase";

describe("countsAsPurchase", () => {
  it("un pedido en línea cobrado es una compra", () => {
    expect(
      countsAsPurchase({ status: "processing", payment_method: ONLINE_PAYMENT_METHOD }),
    ).toBe(true);
    expect(
      countsAsPurchase({ status: "completed", payment_method: ONLINE_PAYMENT_METHOD }),
    ).toBe(true);
  });

  it("el efectivo no: queda «Procesando» sin haber cobrado nada", () => {
    expect(
      countsAsPurchase({ status: "processing", payment_method: CASH_PAYMENT_METHOD }),
    ).toBe(false);
  });

  it("el link de WhatsApp tampoco, aunque lo marquen pagado a mano", () => {
    expect(
      countsAsPurchase({
        status: "processing",
        payment_method: WHATSAPP_PAYMENT_METHOD,
      }),
    ).toBe(false);
  });

  it("un pedido en línea sin cobrar o reembolsado no cuenta", () => {
    for (const status of ["pending", "on-hold", "cancelled", "failed", "refunded"]) {
      expect(countsAsPurchase({ status, payment_method: ONLINE_PAYMENT_METHOD })).toBe(
        false,
      );
    }
  });
});

describe("purchaseEventId", () => {
  it("es `purchase_` + el número de pedido, igual en número o texto", () => {
    expect(purchaseEventId(1234)).toBe("purchase_1234");
    expect(purchaseEventId("1234")).toBe("purchase_1234");
  });
});
