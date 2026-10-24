import { describe, expect, it } from "vitest";
import { hasOwnCatalog } from "@/i18n/messages/catalogs";
import {
  describeBenefit,
  describeHistoryEntry,
  describePurchaseReason,
  localizedText,
} from "./describe";

// ============================================================================
//  Composición de textos del módulo en el BFF.
//
//  WordPress manda claves y datos estructurados (nunca frases): aquí se compone
//  el texto en el idioma de la petición, con caída al español para lo que no
//  esté traducido y a un texto neutro para las claves que este frontend no
//  conoce (los tipos de beneficio y los eventos pueden crecer).
//
//  Una instancia monolingüe borra `messages/en.json`: sin catálogo inglés no hay
//  traducción que comparar, así que las aserciones en inglés se omiten (el texto cae a
//  español) y las españolas se comprueban siempre.
// ============================================================================

const hasEnglish = hasOwnCatalog("en");

describe("describeBenefit", () => {
  it("describe el descuento de socio en todo el catálogo", () => {
    expect(
      describeBenefit(
        { key: "member_discount", data: { percent: 10, scope: "all" } },
        "es",
      ),
    ).toBe("10% de descuento en todo el catálogo");
    if (hasEnglish) {
      expect(
        describeBenefit(
          { key: "member_discount", data: { percent: 10, scope: "all" } },
          "en",
        ),
      ).toBe("10% off everything in the store");
    }
  });

  it("describe el descuento acotado a categorías sin inventar sus nombres", () => {
    expect(
      describeBenefit(
        { key: "member_discount", data: { percent: 15, scope: "categories" } },
        "es",
      ),
    ).toBe("15% de descuento en las categorías participantes");
  });

  it("sin porcentaje válido usa el texto neutro del tipo", () => {
    expect(
      describeBenefit({ key: "member_discount", data: { percent: 0 } }, "es"),
    ).toBe("Descuento de socio");
  });

  it("describe el acceso anticipado y los puntos de activación", () => {
    if (hasEnglish) {
      expect(describeBenefit({ key: "early_access", data: {} }, "en")).toBe(
        "Early access to new releases",
      );
    }
    expect(
      describeBenefit({ key: "activation_points", data: { points: 250 } }, "es"),
    ).toBe("Puntos extra al activar tu membresía: 250");
    expect(describeBenefit({ key: "activation_points", data: {} }, "es")).toBe(
      "Puntos extra al activar tu membresía",
    );
  });

  it("describe la comisión extra y los regalos por referido", () => {
    expect(describeBenefit({ key: "referral_bonus", data: { percent: 5 } }, "es")).toBe(
      "Comisión extra por referidos: 5%",
    );
    expect(
      describeBenefit(
        { key: "referral_gift", data: { level: 3, days: 30, max_per_period: 2 } },
        "es",
      ),
    ).toBe("Regala hasta 2 membresías de 30 días");
    expect(describeBenefit({ key: "referral_gift", data: {} }, "es")).toBe(
      "Regala membresías a tus referidos",
    );
  });

  it("describe los envíos gratis con su cupo", () => {
    expect(describeBenefit({ key: "free_deliveries", data: { count: 2 } }, "es")).toBe(
      "Envíos gratis incluidos: 2",
    );
    if (hasEnglish) {
      expect(describeBenefit({ key: "free_deliveries", data: {} }, "en")).toBe(
        "Free deliveries included",
      );
    }
  });

  it("el `info_perk` escoge el texto del idioma y cae al principal", () => {
    const data = {
      title: { es: "Atención prioritaria", en: "Priority support" },
      text: { es: "Respuesta en 24 h", en: "Answer within 24 h" },
    };

    if (hasEnglish) {
      expect(describeBenefit({ key: "info_perk", data }, "en")).toBe(
        "Priority support",
      );
    }
    expect(describeBenefit({ key: "info_perk", data }, "pt-BR")).toBe(
      "Atención prioritaria",
    );
  });

  it("el `info_perk` cae al texto cuando no hay título", () => {
    expect(
      describeBenefit(
        { key: "info_perk", data: { text: { es: "Acceso al club" } } },
        "es",
      ),
    ).toBe("Acceso al club");
  });

  it("una clave desconocida (tipo de instancia) da un texto neutro", () => {
    expect(describeBenefit({ key: "custom_perk", data: { x: 1 } }, "es")).toBe(
      "Ventaja de membresía",
    );
    if (hasEnglish) {
      expect(describeBenefit({ key: "custom_perk", data: {} }, "en")).toBe(
        "Membership perk",
      );
    }
  });
});

describe("localizedText", () => {
  it("normaliza el sufijo del idioma y cae a la base", () => {
    expect(localizedText({ pt_br: "Olá", en: "Hello" }, "pt-BR")).toBe("Olá");
    expect(localizedText({ en: "Hello" }, "en-US")).toBe("Hello");
    expect(localizedText({ es: "Hola" }, "fr")).toBe("Hola");
    expect(localizedText({}, "es")).toBe("");
    expect(localizedText("no", "es")).toBe("");
  });
});

describe("describeHistoryEntry", () => {
  it("compone el tipo y añade la duración cuando la trae", () => {
    expect(describeHistoryEntry({ type: "activated", data: { days: 30 } }, "es")).toBe(
      "Membresía activada durante 30 días",
    );
    expect(describeHistoryEntry({ type: "activated", data: {} }, "es")).toBe(
      "Membresía activada",
    );
    if (hasEnglish) {
      expect(describeHistoryEntry({ type: "renewed", data: { days: 365 } }, "en")).toBe(
        "Membership renewed for 365 days",
      );
    }
  });

  it("describe los avisos y los finales del ciclo", () => {
    if (hasEnglish) {
      expect(describeHistoryEntry({ type: "expiring", data: { days: 7 } }, "en")).toBe(
        "Your membership expires in 7 days",
      );
    }
    expect(describeHistoryEntry({ type: "expiring", data: {} }, "es")).toBe(
      "Tu membresía está a punto de caducar",
    );
    if (hasEnglish) {
      expect(describeHistoryEntry({ type: "expired", data: {} }, "en")).toBe(
        "Membership expired",
      );
    }
    expect(describeHistoryEntry({ type: "frozen", data: {} }, "es")).toBe(
      "Membresía congelada",
    );
    expect(describeHistoryEntry({ type: "unfrozen", data: {} }, "es")).toBe(
      "Membresía reactivada",
    );
  });

  it("describe las concesiones, revocaciones y avisos de puntos", () => {
    expect(
      describeHistoryEntry({ type: "granted_by_admin", data: { days: 90 } }, "es"),
    ).toBe("Membresía concedida por la tienda durante 90 días");
    expect(
      describeHistoryEntry({ type: "referral_gift_granted", data: {} }, "es"),
    ).toBe("Membresía de regalo recibida");
    expect(describeHistoryEntry({ type: "revoked", data: {} }, "es")).toBe(
      "Membresía revocada",
    );
    expect(describeHistoryEntry({ type: "removed_by_admin", data: {} }, "es")).toBe(
      "Membresía retirada por la tienda",
    );
    expect(describeHistoryEntry({ type: "points_skipped", data: {} }, "es")).toBe(
      "Sin puntos por esta compra",
    );
    if (hasEnglish) {
      expect(
        describeHistoryEntry({ type: "referral_gift_limit_reached", data: {} }, "en"),
      ).toBe("No membership gifts left this period");
    }
  });

  it("un tipo desconocido da un texto neutro, nunca lanza", () => {
    expect(describeHistoryEntry({ type: "future_event", data: { x: 1 } }, "es")).toBe(
      "Movimiento de membresía",
    );
    if (hasEnglish) {
      expect(describeHistoryEntry({ type: "future_event", data: {} }, "en")).toBe(
        "Membership update",
      );
    }
  });
});

describe("describePurchaseReason", () => {
  it("traduce los motivos estables de compra", () => {
    expect(describePurchaseReason("membership_already_indefinite", {}, "es")).toBe(
      "Ya tienes una membresía indefinida que cubre este nivel.",
    );
    expect(
      describePurchaseReason("membership_account_too_new", { days_left: 5 }, "es"),
    ).toBe("Tu cuenta necesita 5 días más para acceder a este nivel.");
    if (hasEnglish) {
      expect(describePurchaseReason("membership_account_too_new", {}, "en")).toBe(
        "Your account is still too new for this level.",
      );
    }
  });

  it("un motivo vacío (se puede comprar) no compone nada", () => {
    expect(describePurchaseReason("", {}, "es")).toBe("");
  });

  it("un motivo desconocido da un texto neutro", () => {
    expect(describePurchaseReason("membership_future", {}, "es")).toBe(
      "No puedes comprar este nivel ahora mismo.",
    );
  });
});
