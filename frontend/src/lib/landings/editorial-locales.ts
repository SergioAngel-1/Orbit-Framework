import { routing } from "@/i18n/routing";
import { localeSuffix } from "@/lib/i18n/pick-localized";
import type { EditorialLandingConfig } from "@/lib/config/types";

// ============================================================================
//  ¿En qué idiomas existe una landing editorial?
//
//  El idioma por defecto es el contenido nativo y siempre existe. Un idioma
//  secundario «existe» si la landing trae traducción de alguno de sus campos de
//  peso: sin ella la dirección sirve el contenido del idioma por defecto, pero
//  NO se anuncia (ni hreflang ni sitemap) — spec §8.1. El mismo criterio decide el
//  `lang` del subárbol.
//
//  Módulo PURO: lo usan el lector del servidor, el sitemap y los tests.
// ============================================================================

const SLOTS = ["s1", "s2", "s3", "s4", "s5"] as const;

/** Los textos de más peso de la config de una landing, para un idioma (`pt_br`). */
function probeValues(cfg: EditorialLandingConfig, suffix: string): unknown[] {
  return [
    cfg.seo?.[`title_${suffix}`],
    cfg[`lead_body_${suffix}`],
    cfg.closing?.[`text_${suffix}`],
    ...SLOTS.map((slot) => cfg.sections?.[slot]?.[`body_${suffix}`]),
  ];
}

/** Idiomas del sitio en los que existe la landing; el por defecto va primero. */
export function editorialTranslatedLocales(
  cfg: EditorialLandingConfig | null | undefined,
): string[] {
  if (!cfg) return [routing.defaultLocale];
  return routing.locales.filter((locale) => {
    if (locale === routing.defaultLocale) return true;
    return probeValues(cfg, localeSuffix(locale)).some(
      (value) => typeof value === "string" && value.trim() !== "",
    );
  });
}
