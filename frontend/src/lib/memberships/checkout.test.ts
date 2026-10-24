import { describe, expect, it } from "vitest";
import {
  MEMBERSHIP_CHECKOUT_REASONS,
  membershipCheckoutError,
  membershipCheckoutMessage,
} from "./checkout";

// ============================================================================
//  Traducción de los errores de la Store API del módulo `memberships`.
//
//  WordPress manda un código `hwe_<motivo>` (y datos estructurados). El BFF
//  devuelve un motivo estable —sin prefijo— para que la UI no interprete
//  frases, y conserva solo los datos útiles con su tipo. Un código ajeno no se
//  toca: sigue el camino de error que ya tenía.
// ============================================================================

const CODES = [
  "membership_required",
  "membership_early_access",
  "membership_free_delivery_unavailable",
  "membership_login_required",
  "membership_one_per_cart",
  "membership_not_purchasable",
  "membership_account_too_new",
  "membership_account_pending",
  "membership_already_indefinite",
] as const;

describe("membershipCheckoutError", () => {
  it.each(CODES)("traduce hwe_%s a un motivo estable con texto", (reason) => {
    const translated = membershipCheckoutError(`hwe_${reason}`);

    expect(translated?.reason).toBe(reason);
    expect(membershipCheckoutMessage(reason).length).toBeGreaterThan(0);
    expect(MEMBERSHIP_CHECKOUT_REASONS).toContain(reason);
  });

  it("un código ajeno o vacío no se traduce", () => {
    expect(membershipCheckoutError("woocommerce_rest_invalid_address")).toBeNull();
    expect(membershipCheckoutError("hwe_loyalty_redeem_failed")).toBeNull();
    expect(membershipCheckoutError(null)).toBeNull();
    expect(membershipCheckoutError(undefined)).toBeNull();
  });

  it("conserva solo los datos útiles y con su tipo", () => {
    const translated = membershipCheckoutError("hwe_membership_account_too_new", {
      days_left: 4,
      min_account_age_days: 30,
      account_age_days: 26,
      level_id: 3,
      current_level_id: 2,
      days_left_texto: "4",
      clave_interna: "no viaja",
    });

    expect(translated).toEqual({
      reason: "membership_account_too_new",
      details: {
        days_left: 4,
        min_account_age_days: 30,
        account_age_days: 26,
        level_id: 3,
        current_level_id: 2,
      },
    });
  });

  it("conserva el nivel y la fecha de apertura de un bloqueo", () => {
    const translated = membershipCheckoutError("hwe_membership_early_access", {
      level_slug: "nivel-a",
      level_name: "Nivel A",
      opens_at: "2026-11-01T00:00:00Z",
      min_rank: 2,
      product_id: 99,
      key: "linea-2",
    });

    expect(translated?.details).toEqual({
      level_slug: "nivel-a",
      level_name: "Nivel A",
      opens_at: "2026-11-01T00:00:00Z",
      min_rank: 2,
      product_id: 99,
      key: "linea-2",
    });
  });

  it("acepta una apertura nula y descarta datos con un tipo inesperado", () => {
    const translated = membershipCheckoutError("hwe_membership_early_access", {
      opens_at: null,
      level_slug: 9,
      min_rank: -1,
    });

    expect(translated).toEqual({
      reason: "membership_early_access",
      details: { opens_at: null },
    });
  });

  it("sin datos estructurados sigue devolviendo el motivo", () => {
    expect(membershipCheckoutError("hwe_membership_required", null)).toEqual({
      reason: "membership_required",
      details: {},
    });
  });
});
