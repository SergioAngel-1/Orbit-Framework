import { pickLocalized } from "@/lib/i18n/pick-localized";
import type { SiteConfig } from "./types";

// ============================================================================
//  Textos de marca resueltos al locale.
//
//  `brand.tagline` y `brand.description` son los únicos textos de marca que se
//  publican tal cual en superficies por-locale: el pie de página, la meta
//  description de la home, la descripción de Organization/WebSite en el
//  JSON-LD y la tarjeta social. Son traducibles en el Control Center: cada
//  idioma secundario tiene su variante y, vacía, hereda el idioma por defecto.
// ============================================================================

/** Tagline del locale. */
export function brandTagline(config: SiteConfig, locale: string): string {
  return pickLocalized(config.brand, "tagline", locale) ?? "";
}

/** Descripción breve del locale. */
export function brandDescription(config: SiteConfig, locale: string): string {
  return pickLocalized(config.brand, "description", locale) ?? "";
}
