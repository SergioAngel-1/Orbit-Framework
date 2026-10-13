import { routing } from "@/i18n/routing";
import { hasTranslation, snakeCase } from "@/lib/i18n/translation";

// ============================================================================
//  ¿En qué idiomas existe una landing de producto?
//
//  El idioma por defecto es el contenido nativo y siempre existe. Un idioma
//  secundario «existe» si la landing trae traducción de alguno de los campos de
//  peso: sin ella la dirección sirve el contenido del idioma por defecto, pero
//  NO se anuncia (ni hreflang ni sitemap) — spec §8.1. El mismo criterio decide
//  el `lang` del subárbol.
//
//  Módulo PURO: lo usan el lector del servidor, el sitemap y los tests.
// ============================================================================

/**
 * Campos (nombre de GraphQL) cuya traducción da por traducida la landing. Son
 * los de más peso del molde; una traducción parcial cuenta igualmente.
 */
export const LANDING_PROBE_FIELDS = [
  "seoTitle",
  "heroSubtitle",
  "eduBody",
  "aboutBody",
  "closingText",
] as const;

/**
 * Idiomas del sitio en los que existe la landing. `raw` es el nodo de
 * `Product.landing` tal como lo deja `flattenTranslations` (claves
 * `<campo>_<idioma>`). El idioma por defecto va primero.
 */
export function landingTranslatedLocales(raw: object | null | undefined): string[] {
  if (!raw) return [routing.defaultLocale];
  return routing.locales.filter(
    (locale) =>
      locale === routing.defaultLocale ||
      LANDING_PROBE_FIELDS.some((field) =>
        hasTranslation(raw, locale, snakeCase(field)),
      ),
  );
}

/** Idioma en el que se sirve realmente la landing pedida en `locale`. */
export function landingContentLocale(
  raw: object | null | undefined,
  locale: string,
): string {
  return landingTranslatedLocales(raw).includes(locale)
    ? locale
    : routing.defaultLocale;
}
