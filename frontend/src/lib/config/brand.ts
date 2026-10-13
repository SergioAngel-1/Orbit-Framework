import { pickLocalized } from "@/lib/i18n/pick-localized";
import type { SiteConfig } from "./types";

// ============================================================================
//  Textos de marca resueltos al locale.
//
//  `brand.tagline` y `brand.description` son los únicos textos de marca que se
//  publican tal cual en superficies por-locale: el pie de página, la meta
//  description de la home, la descripción de Organization/WebSite en el
//  JSON-LD y la tarjeta social. Sin traducción, todas ellas servían español
//  dentro de páginas declaradas en inglés.
// ============================================================================

/** Tagline del locale. `_en` vacío hereda el español. */
export function brandTagline(config: SiteConfig, locale: string): string {
  return pickLocalized(config.brand.tagline, config.brand.tagline_en, locale) ?? "";
}

/** Descripción breve del locale. `_en` vacía hereda la española. */
export function brandDescription(config: SiteConfig, locale: string): string {
  return (
    pickLocalized(config.brand.description, config.brand.description_en, locale) ?? ""
  );
}
