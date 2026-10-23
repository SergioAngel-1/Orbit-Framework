import { describe, expect, it } from "vitest";
import type { LoyaltyEntryType } from "./types";
import { describeEntry } from "./describe";

const base = {
  id: 1,
  points: 10,
  created_at: "2026-10-22T10:00:00Z",
  expires_at: null,
};

/** Todos los tipos del contrato (§5.2) más sus datos de ejemplo. */
const ENTRIES: [LoyaltyEntryType, Record<string, unknown>][] = [
  ["cashback", { order: 42 }],
  ["referral_commission_l1", { order: 42 }],
  ["referral_commission_l2", { order: 42 }],
  ["referral_product_bonus", { order: 42 }],
  ["referral_signup_l1", {}],
  ["referral_signup_l2", {}],
  ["registration", {}],
  ["review", { source: "product" }],
  ["birthday", { year: 2026 }],
  ["transfer_in", { counterpart_name: "Ana", fee: 0 }],
  ["redeem_refund", { order: 42 }],
  ["admin_credit", { note: "Ajuste del equipo" }],
  ["redeem", { order: 42, discount: "12.50" }],
  ["transfer_out", { counterpart_name: "Ana", fee: 5 }],
  ["transfer_fee", { counterpart_name: "Ana", fee: 5 }],
  ["expire", {}],
  ["admin_debit", {}],
  ["earn_reversal", { order: 42, original_type: "cashback" }],
];

describe("describeEntry", () => {
  it.each(ENTRIES)("compone el texto de `%s`", (type, data) => {
    const text = describeEntry({ ...base, type, data }, "es");

    expect(text.length).toBeGreaterThan(0);
    expect(text).not.toContain("{");
    expect(text).not.toContain("undefined");
    expect(text).not.toContain("null");
  });

  it("incluye el pedido en los movimientos que lo tienen", () => {
    expect(
      describeEntry({ ...base, type: "cashback", data: { order: 42 } }, "es"),
    ).toContain("42");
    expect(
      describeEntry(
        { ...base, type: "redeem", data: { order: 77, discount: "1" } },
        "es",
      ),
    ).toContain("77");
    expect(
      describeEntry(
        {
          ...base,
          type: "earn_reversal",
          data: { order: 12, original_type: "cashback" },
        },
        "es",
      ),
    ).toContain("12");
  });

  it("omite el pedido sin inventarlo cuando falta el dato", () => {
    const text = describeEntry({ ...base, type: "cashback", data: {} }, "es");

    expect(text.length).toBeGreaterThan(0);
    expect(text).not.toContain("(");
  });

  it("nombra a la otra parte de una transferencia y nunca muestra ids", () => {
    const text = describeEntry(
      { ...base, type: "transfer_out", data: { counterpart_name: "Ana", fee: 5 } },
      "es",
    );

    expect(text).toContain("Ana");
    expect(text).not.toContain("fee");
  });

  it("distingue una reseña de producto de una del sitio", () => {
    const product = describeEntry(
      { ...base, type: "review", data: { source: "product" } },
      "es",
    );
    const site = describeEntry(
      { ...base, type: "review", data: { source: "site" } },
      "es",
    );
    const generic = describeEntry({ ...base, type: "review", data: {} }, "es");

    expect(product).not.toBe(site);
    expect(generic.length).toBeGreaterThan(0);
  });

  it("muestra la nota libre de un ajuste manual y la omite si no existe", () => {
    const withNote = describeEntry(
      { ...base, type: "admin_credit", data: { note: "Regalo de bienvenida" } },
      "es",
    );
    const withoutNote = describeEntry(
      { ...base, type: "admin_credit", data: {} },
      "es",
    );

    expect(withNote).toContain("Regalo de bienvenida");
    expect(withoutNote).not.toContain(":");
  });

  it("un tipo desconocido da un texto neutro en vez de fallar", () => {
    expect(
      describeEntry(
        { ...base, type: "tipo_del_futuro", data: { anything: true } },
        "es",
      ),
    ).toBe("Movimiento de fidelización");
    expect(describeEntry({ ...base, type: "tipo_del_futuro", data: null }, "en")).toBe(
      "Loyalty movement",
    );
  });

  it("tolera datos malformados sin lanzar", () => {
    expect(() =>
      describeEntry(
        { ...base, type: "transfer_in", data: { counterpart_name: 42 } },
        "es",
      ),
    ).not.toThrow();
    expect(
      describeEntry({ ...base, type: "birthday", data: { year: "ayer" } }, "es").length,
    ).toBeGreaterThan(0);
  });

  it("compone el texto en el idioma pedido", () => {
    const entry = { ...base, type: "registration", data: {} };

    expect(describeEntry(entry, "es")).not.toBe(describeEntry(entry, "en"));
    expect(describeEntry(entry, "fr")).toBe(describeEntry(entry, "es"));
  });
});
