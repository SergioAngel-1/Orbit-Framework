import { describe, it, expect } from "vitest";
import {
  enabledPaymentChoices,
  formatOrderItemsForMessage,
  paymentChoiceFor,
  paymentMethodFor,
  shouldStartGatewayPayment,
} from "./checkout-options";

const payments = (
  over: Partial<{
    gateway_enabled: boolean;
    whatsapp_enabled: boolean;
    cash_enabled: boolean;
  }> = {},
) => ({
  payments: {
    gateway_enabled: over.gateway_enabled ?? true,
    whatsapp_enabled: over.whatsapp_enabled ?? false,
    cash_enabled: over.cash_enabled ?? false,
  },
});

describe("enabledPaymentChoices", () => {
  it("ofrece cada medio activo, en orden fijo", () => {
    expect(
      enabledPaymentChoices(payments({ whatsapp_enabled: true, cash_enabled: true })),
    ).toEqual(["online", "whatsapp", "cash"]);
  });

  it("WhatsApp es independiente: puede ir sin pago en línea", () => {
    expect(
      enabledPaymentChoices(
        payments({ gateway_enabled: false, whatsapp_enabled: true }),
      ),
    ).toEqual(["whatsapp"]);
  });

  it("con todo apagado queda el pago en línea", () => {
    expect(enabledPaymentChoices(payments({ gateway_enabled: false }))).toEqual([
      "online",
    ]);
  });
});

describe("paymentMethodFor / paymentChoiceFor / shouldStartGatewayPayment", () => {
  it("cada medio crea el pedido con su propio gateway, y vuelta", () => {
    expect(paymentMethodFor("online")).toBe("hwe_offline");
    expect(paymentMethodFor("whatsapp")).toBe("hwe_whatsapp_link");
    expect(paymentMethodFor("cash")).toBe("cod");
    expect(paymentChoiceFor("hwe_whatsapp_link")).toBe("whatsapp");
    expect(paymentChoiceFor("bacs")).toBeNull();
  });

  it("solo el pago en línea lleva a la pasarela", () => {
    expect(shouldStartGatewayPayment("online")).toBe(true);
    expect(shouldStartGatewayPayment("whatsapp")).toBe(false);
    expect(shouldStartGatewayPayment("cash")).toBe(false);
  });
});

describe("formatOrderItemsForMessage", () => {
  it("una línea por producto con su cantidad", () => {
    expect(
      formatOrderItemsForMessage([
        { name: "Producto A", quantity: 2 },
        { name: " Producto B ", quantity: 1 },
        { name: "", quantity: 1 },
      ]),
    ).toBe("• 2 × Producto A\n• 1 × Producto B");
  });

  it("los detalles de una línea van en la misma viñeta, sin los vacíos", () => {
    expect(
      formatOrderItemsForMessage([
        {
          name: "Caja personalizada",
          quantity: 1,
          details: ["Color: rojo", "Talla: M"],
        },
        { name: "Taller", quantity: 1, details: ["Modalidad: en línea", " "] },
      ]),
    ).toBe(
      "• 1 × Caja personalizada (Color: rojo; Talla: M)\n" +
        "• 1 × Taller (Modalidad: en línea)",
    );
  });
});
