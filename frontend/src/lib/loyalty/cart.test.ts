import { describe, expect, it } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { LoyaltyConfig } from "@/lib/config/types";
import { maxRedeemable, parseLoyaltyCartExtension } from "./cart";

/** Config base con aritmética sencilla; cada prueba ajusta solo lo que mide. */
function config(overrides: Partial<LoyaltyConfig> = {}): LoyaltyConfig {
  return {
    ...CONFIG_DEFAULTS.loyalty,
    point_value: "1",
    min_redeem: "0",
    max_redeem_per_order: "0",
    full_payment_enabled: false,
    min_cash_due: "0.01",
    ...overrides,
  };
}

const input = (
  overrides: Partial<{
    itemsTotal: number;
    shippingTotal: number;
    feesTotal: number;
    balance: number;
  }> = {},
) => ({
  itemsTotal: 5000,
  shippingTotal: 2000,
  feesTotal: 0,
  balance: 100000,
  ...overrides,
});

describe("maxRedeemable", () => {
  it("recorta al saldo disponible", () => {
    expect(maxRedeemable(input({ balance: 500 }), config())).toBe(500);
  });

  it("recorta al máximo por pedido (0 = sin tope)", () => {
    expect(maxRedeemable(input(), config({ max_redeem_per_order: "400" }))).toBe(400);
    // Sin pago completo nunca se cubre el envío: el tope es el importe no envío.
    expect(maxRedeemable(input(), config({ max_redeem_per_order: "0" }))).toBe(5000);
  });

  it("sin pago completo el descuento no cubre el envío", () => {
    const points = maxRedeemable(input(), config({ min_cash_due: "1000" }));

    // Quedan 2.000 por pagar en dinero (el envío) y el mínimo se respeta.
    expect(points).toBe(5000);
    expect(points * 1).toBeLessThan(7000);
  });

  it("sin pago completo deja el mínimo en dinero aunque el envío sea 0", () => {
    expect(
      maxRedeemable(input({ shippingTotal: 0 }), config({ min_cash_due: "1000" })),
    ).toBe(4000);
  });

  it("un carrito por debajo del mínimo en dinero no admite canje", () => {
    expect(
      maxRedeemable(
        input({ itemsTotal: 800, shippingTotal: 100 }),
        config({ min_cash_due: "1000" }),
      ),
    ).toBe(0);
  });

  it("con pago completo puede cubrir el total, envío incluido", () => {
    expect(maxRedeemable(input(), config({ full_payment_enabled: true }))).toBe(7000);
  });

  it("con pago completo el tope por pedido sigue mandando", () => {
    expect(
      maxRedeemable(
        input(),
        config({ full_payment_enabled: true, max_redeem_per_order: "100" }),
      ),
    ).toBe(100);
  });

  it("por debajo del mínimo de canje no aplica", () => {
    expect(maxRedeemable(input({ balance: 50 }), config({ min_redeem: "100" }))).toBe(
      0,
    );
  });

  it("sin valor de la unidad no hay canje", () => {
    expect(maxRedeemable(input(), config({ point_value: "0" }))).toBe(0);
    expect(maxRedeemable(input(), config({ point_value: "-0.5" }))).toBe(0);
  });

  it("redondea los puntos hacia abajo", () => {
    expect(
      maxRedeemable(
        input({ itemsTotal: 100, shippingTotal: 0 }),
        config({ point_value: "0.03" }),
      ),
    ).toBe(3333);
  });

  it("cuenta los fees ajenos como importe no envío, igual que WordPress", () => {
    expect(
      maxRedeemable(
        input({ itemsTotal: 1000, shippingTotal: 5000, feesTotal: 4000 }),
        config({ min_cash_due: "1000" }),
      ),
    ).toBe(5000);
  });

  it("un saldo de 0 no permite canjear", () => {
    expect(maxRedeemable(input({ balance: 0 }), config())).toBe(0);
  });

  it("recorta al porcentaje de la base sin envío (0 = sin tope)", () => {
    // 10 % de 5.000 = 500, con los fees ajenos dentro de la base.
    expect(
      maxRedeemable(
        input({ itemsTotal: 4000, shippingTotal: 2000, feesTotal: 1000 }),
        config({ max_redeem_percent: "10" }),
      ),
    ).toBe(500);
    expect(maxRedeemable(input(), config({ max_redeem_percent: "0" }))).toBe(5000);
  });

  it("el porcentaje se combina con el resto de topes tomando el menor", () => {
    expect(
      maxRedeemable(
        input(),
        config({ max_redeem_percent: "10", max_redeem_per_order: "200" }),
      ),
    ).toBe(200);
    expect(
      maxRedeemable(input({ balance: 300 }), config({ max_redeem_percent: "10" })),
    ).toBe(300);
  });

  it("el porcentaje redondea los puntos hacia abajo", () => {
    expect(
      maxRedeemable(
        input({ itemsTotal: 100, shippingTotal: 0 }),
        config({ point_value: "0.03", max_redeem_percent: "10" }),
      ),
    ).toBe(333);
  });
});

describe("parseLoyaltyCartExtension", () => {
  const extension = {
    available: true,
    reason: null,
    balance: 500,
    points_applied: 100,
    discount: "1000",
    max_redeemable: 400,
    min_redeem: 100,
    full_payment_available: false,
  };

  it("lee la extensión `hwe-loyalty` del carrito", () => {
    expect(
      parseLoyaltyCartExtension({ extensions: { "hwe-loyalty": extension } }),
    ).toEqual(extension);
  });

  it("tolera la extensión ausente", () => {
    expect(parseLoyaltyCartExtension(null)).toBeNull();
    expect(parseLoyaltyCartExtension(undefined)).toBeNull();
    expect(parseLoyaltyCartExtension({})).toBeNull();
    expect(parseLoyaltyCartExtension({ extensions: null })).toBeNull();
    expect(parseLoyaltyCartExtension({ extensions: {} })).toBeNull();
    expect(
      parseLoyaltyCartExtension({ extensions: { "hwe-loyalty": null } }),
    ).toBeNull();
  });

  it("tolera una extensión malformada sin lanzar", () => {
    expect(
      parseLoyaltyCartExtension({ extensions: { "hwe-loyalty": { available: "sí" } } }),
    ).toBeNull();
    expect(
      parseLoyaltyCartExtension({
        extensions: {
          "hwe-loyalty": { ...extension, balance: "500" },
        },
      }),
    ).toBeNull();
    expect(
      parseLoyaltyCartExtension({
        extensions: { "hwe-loyalty": { ...extension, discount: 1000 } },
      }),
    ).toBeNull();
    expect(
      parseLoyaltyCartExtension({
        extensions: { "hwe-loyalty": { ...extension, discount: "-1000" } },
      }),
    ).toBeNull();
    expect(
      parseLoyaltyCartExtension({
        extensions: { "hwe-loyalty": { ...extension, points_applied: -1 } },
      }),
    ).toBeNull();
  });

  it("conserva el motivo conocido y normaliza uno desconocido a null", () => {
    expect(
      parseLoyaltyCartExtension({
        extensions: {
          "hwe-loyalty": { ...extension, available: false, reason: "guest" },
        },
      })?.reason,
    ).toBe("guest");
    expect(
      parseLoyaltyCartExtension({
        extensions: {
          "hwe-loyalty": { ...extension, available: false, reason: "futuro" },
        },
      })?.reason,
    ).toBeNull();
  });

  it("ignora claves extra de la extensión", () => {
    expect(
      parseLoyaltyCartExtension({
        extensions: { "hwe-loyalty": { ...extension, extra: true } },
      }),
    ).toEqual(extension);
  });
});
