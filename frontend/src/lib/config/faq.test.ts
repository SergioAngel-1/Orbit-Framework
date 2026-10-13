import { describe, expect, it } from "vitest";
import { faqItems, paymentsFaqItems } from "./faq";
import { CONFIG_DEFAULTS, type SiteConfig } from "./index";

const config = (geo: Record<string, string>): SiteConfig => ({
  ...CONFIG_DEFAULTS,
  geo: { ...CONFIG_DEFAULTS.geo, ...geo },
});

describe("faqItems", () => {
  it("parsea el bloque en español", () => {
    const items = faqItems(config({ faq: "¿Envíos? | A todo el país." }), "es");
    expect(items).toEqual([{ question: "¿Envíos?", answer: "A todo el país." }]);
  });

  it("en inglés usa el bloque _en y cae al español si está vacío", () => {
    const withEn = config({ faq: "¿Envíos? | Sí.", faq_en: "Shipping? | Yes." });
    expect(faqItems(withEn, "en")).toEqual([{ question: "Shipping?", answer: "Yes." }]);

    const withoutEn = config({ faq: "¿Envíos? | Sí.", faq_en: "" });
    expect(faqItems(withoutEn, "en")).toEqual([
      { question: "¿Envíos?", answer: "Sí." },
    ]);
  });
});

describe("paymentsFaqItems", () => {
  it("vacío = sección ausente", () => {
    expect(paymentsFaqItems(config({ faq_payments: "" }), "es")).toEqual([]);
  });

  it("resuelve al locale igual que el bloque general", () => {
    const c = config({ faq_payments: "¿PSE? | Sí.", faq_payments_en: "PSE? | Yes." });
    expect(paymentsFaqItems(c, "es")).toEqual([{ question: "¿PSE?", answer: "Sí." }]);
    expect(paymentsFaqItems(c, "en")).toEqual([{ question: "PSE?", answer: "Yes." }]);
  });
});
