import { describe, expect, it } from "vitest";
import { MEMBERSHIPS_CART_EXTENSION, parseMembershipsCartExtension } from "./cart";

// ============================================================================
//  Lectura de `extensions["hwe-memberships"]`.
//
//  La extensión la publica WordPress; el navegador nunca decide importes ni
//  disponibilidad. El parser tolera que el módulo esté apagado (no hay
//  extensión) y que una versión futura añada motivos que este frontend no
//  conoce, sin romper el resto del carrito.
// ============================================================================

const LEVEL = { id: 4, slug: "nivel-a", rank: 2, name: "Nivel A" };

const EXTENSION = {
  level: LEVEL,
  member_discount: {
    total: "1500",
    lines: { "linea-1": "1500" },
  },
  free_delivery: {
    available: true,
    remaining: 2,
    requested: true,
    applied: true,
    reason: "membership_free_delivery" as const,
  },
  blocked_items: [
    {
      key: "linea-2",
      product_id: 99,
      reason: "membership_early_access" as const,
      min_rank: 3,
      level_slug: "nivel-b",
      level_name: "Nivel B",
      opens_at: "2026-11-01T00:00:00Z",
    },
  ],
  purchase_notice: "upgrade" as const,
};

const cartWith = (extension: unknown) => ({
  extensions: { [MEMBERSHIPS_CART_EXTENSION]: extension },
});

describe("parseMembershipsCartExtension", () => {
  it("lee el contrato completo que publica WordPress", () => {
    expect(parseMembershipsCartExtension(cartWith(EXTENSION))).toEqual(EXTENSION);
  });

  it("devuelve null si el módulo está apagado y no hay extensión", () => {
    expect(parseMembershipsCartExtension({ extensions: {} })).toBeNull();
    expect(parseMembershipsCartExtension({})).toBeNull();
  });

  it("devuelve null si `extensions` no es un objeto", () => {
    expect(parseMembershipsCartExtension({ extensions: "hwe-memberships" })).toBeNull();
    expect(parseMembershipsCartExtension(null)).toBeNull();
  });

  it("devuelve null si la extensión está malformada", () => {
    expect(
      parseMembershipsCartExtension(
        cartWith({
          ...EXTENSION,
          free_delivery: { ...EXTENSION.free_delivery, available: "sí" },
        }),
      ),
    ).toBeNull();
    expect(
      parseMembershipsCartExtension(
        cartWith({ ...EXTENSION, member_discount: { total: "-1", lines: {} } }),
      ),
    ).toBeNull();
  });

  it("acepta un descuento sin líneas", () => {
    const parsed = parseMembershipsCartExtension(
      cartWith({ ...EXTENSION, member_discount: { total: "0", lines: {} } }),
    );

    expect(parsed?.member_discount).toEqual({ total: "0", lines: {} });
  });

  it("normaliza un motivo de envío desconocido a null sin perder el resto", () => {
    const parsed = parseMembershipsCartExtension(
      cartWith({
        ...EXTENSION,
        free_delivery: { ...EXTENSION.free_delivery, reason: "motivo_futuro" },
      }),
    );

    expect(parsed?.free_delivery.reason).toBeNull();
    expect(parsed?.free_delivery.applied).toBe(true);
    expect(parsed?.level).toEqual(LEVEL);
  });

  it("normaliza un aviso de compra desconocido a null", () => {
    const parsed = parseMembershipsCartExtension(
      cartWith({ ...EXTENSION, purchase_notice: "aviso_futuro" }),
    );

    expect(parsed?.purchase_notice).toBeNull();
  });

  it("conserva un nivel nulo y una apertura sin fecha", () => {
    const parsed = parseMembershipsCartExtension(
      cartWith({
        ...EXTENSION,
        level: null,
        blocked_items: [
          {
            ...EXTENSION.blocked_items[0],
            reason: "membership_required",
            opens_at: null,
          },
        ],
      }),
    );

    expect(parsed?.level).toBeNull();
    expect(parsed?.blocked_items[0]).toMatchObject({
      reason: "membership_required",
      opens_at: null,
    });
  });
});
