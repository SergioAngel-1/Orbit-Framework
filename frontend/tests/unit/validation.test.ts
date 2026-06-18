import { describe, it, expect } from "vitest";
import { createPaymentSchema } from "@/lib/validation/payments";
import { checkoutSchema } from "@/lib/validation/store";

describe("createPaymentSchema", () => {
  it("acepta reference numérica o string numérica y la normaliza a string", () => {
    expect(createPaymentSchema.parse({ reference: 123 }).reference).toBe("123");
    expect(createPaymentSchema.parse({ reference: "123" }).reference).toBe("123");
  });
  it("rechaza reference no numérica", () => {
    expect(createPaymentSchema.safeParse({ reference: "abc" }).success).toBe(false);
    expect(createPaymentSchema.safeParse({ reference: -1 }).success).toBe(false);
  });
  it("rechaza returnUrl no-URL", () => {
    expect(
      createPaymentSchema.safeParse({ reference: 1, returnUrl: "no" }).success,
    ).toBe(false);
  });
});

describe("checkoutSchema", () => {
  it("exige billing_address y payment_method", () => {
    const ok = checkoutSchema.safeParse({
      billing_address: { email: "a@b.com" },
      payment_method: "cod",
    });
    expect(ok.success).toBe(true);
  });
  it("rechaza country que no sea de 2 letras", () => {
    const bad = checkoutSchema.safeParse({
      billing_address: { country: "ESP" },
      payment_method: "cod",
    });
    expect(bad.success).toBe(false);
  });
});
