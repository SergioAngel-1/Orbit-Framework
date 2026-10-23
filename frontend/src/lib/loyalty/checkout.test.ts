import { describe, expect, it } from "vitest";
import {
  LOYALTY_PAID_WITH_POINTS_META,
  loyaltyCheckoutErrorReason,
  orderPaidWithPoints,
} from "./checkout";

// ============================================================================
//  Contrato de fidelización que consume el checkout del BFF:
//   - los dos errores del canje de la Store API se traducen a un motivo estable;
//   - la marca de «pagado con puntos» se lee de la meta del pedido (la prueba
//     es el libro, no el navegador).
// ============================================================================

describe("loyaltyCheckoutErrorReason", () => {
  it.each([
    ["hwe_loyalty_insufficient_points", "loyalty_insufficient_points"],
    ["hwe_loyalty_redeem_failed", "loyalty_redeem_failed"],
  ])("traduce %s a un motivo estable", (code, reason) => {
    expect(loyaltyCheckoutErrorReason(code)).toBe(reason);
  });

  it("cualquier otro código no es un error del canje", () => {
    expect(loyaltyCheckoutErrorReason("woocommerce_rest_invalid_item")).toBeNull();
    expect(loyaltyCheckoutErrorReason("")).toBeNull();
    expect(loyaltyCheckoutErrorReason(undefined)).toBeNull();
  });
});

describe("orderPaidWithPoints", () => {
  it("solo es verdadero con la marca exacta que escribe WordPress", () => {
    expect(
      orderPaidWithPoints([
        { key: LOYALTY_PAID_WITH_POINTS_META, value: "yes" },
        { key: "_hwe_loyalty_redeemed_points", value: 250 },
      ]),
    ).toBe(true);
  });

  it.each([
    ["la meta no está", [{ key: "_hwe_loyalty_redeemed_points", value: 250 }]],
    [
      "la marca tiene otro valor",
      [{ key: LOYALTY_PAID_WITH_POINTS_META, value: "no" }],
    ],
    ["la marca es booleana", [{ key: LOYALTY_PAID_WITH_POINTS_META, value: true }]],
    ["no hay meta", undefined],
  ])("%s: no se da por pagado", (_label, meta) => {
    expect(orderPaidWithPoints(meta)).toBe(false);
  });
});
