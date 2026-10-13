import { pickLocalized } from "@/lib/i18n/pick-localized";
import { parseFaq, type FaqItem } from "@/lib/seo/faq";
import type { SiteConfig } from "./types";

// ============================================================================
//  Preguntas frecuentes resueltas al locale.
//
//  Dos bloques independientes en HWE Control Center → GEO: las preguntas
//  generales (`geo.faq`) y las de pagos electrónicos (`geo.faq_payments`).
//  Ambos traducibles por idioma, y la traducción es por BLOQUE COMPLETO, no
//  por línea: una variante no vacía reemplaza todo el bloque, así que si trae
//  menos preguntas, en ese idioma se ven menos. Es la contrapartida de que el
//  formato sea un textarea de líneas "pregunta | respuesta" y no un campo por
//  pregunta.
// ============================================================================

/** Preguntas generales del locale. Sin variante, hereda el idioma por defecto. */
export function faqItems(config: SiteConfig, locale: string): FaqItem[] {
  return parseFaq(pickLocalized(config.geo, "faq", locale));
}

/** Preguntas sobre pagos electrónicos del locale. Vacío = sección ausente. */
export function paymentsFaqItems(config: SiteConfig, locale: string): FaqItem[] {
  return parseFaq(pickLocalized(config.geo, "faq_payments", locale));
}
