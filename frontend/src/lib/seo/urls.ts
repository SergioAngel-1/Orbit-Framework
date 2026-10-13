import { routing } from "@/i18n/routing";

/**
 * Helpers de URL conscientes del locale para SEO (canónicas, hreflang, JSON-LD).
 *
 * Convención del proyecto (`localePrefix: "as-needed"`): el locale por defecto
 * (es) NO lleva prefijo; el resto sí (/en/...). Mantener esto centralizado evita
 * divergencias entre sitemap, metadatos y datos estructurados.
 */

/** Ruta relativa con prefijo de locale (vacía → "/"). */
export function localizedPath(path: string, locale: string): string {
  const prefix = locale === routing.defaultLocale ? "" : `/${locale}`;
  // La home de un idioma con prefijo es `/en`, no `/en/` (así la sirve next-intl).
  const p = `${prefix}${path === "/" ? "" : path}`;
  return p === "" ? "/" : p;
}

/**
 * Opciones de las alternativas de idioma de una URL.
 *
 * `locales` son los idiomas en los que ESA entidad existe de verdad. Por
 * defecto, todos. Una ficha o entrada sin traducir sirve el MISMO contenido en
 * el otro idioma, y declararlo como alternativa lo percibe un buscador como un
 * casi-duplicado: se omite del hreflang y la canónica de esa URL apunta al
 * idioma por defecto.
 */
export interface AlternatesOptions {
  locales?: readonly string[];
}

function hasLocale(options: AlternatesOptions, locale: string): boolean {
  return !options.locales || options.locales.includes(locale);
}

/** Mapa hreflang (incluye x-default → locale por defecto). Rutas relativas. */
export function hreflangLanguages(
  path: string,
  options: AlternatesOptions = {},
): Record<string, string> {
  const languages: Record<string, string> = {};
  for (const locale of routing.locales) {
    if (locale !== routing.defaultLocale && !hasLocale(options, locale)) continue;
    languages[locale] = localizedPath(path, locale);
  }
  languages["x-default"] = localizedPath(path, routing.defaultLocale);
  return languages;
}

/**
 * Bloque `alternates` listo para `generateMetadata` (relativo; Next lo resuelve
 * contra `metadataBase`). `canonical` apunta al locale actual, salvo que la
 * entidad no exista en él: entonces es la del idioma por defecto.
 */
export function alternatesFor(
  path: string,
  locale: string,
  options: AlternatesOptions = {},
) {
  const canonicalLocale =
    locale === routing.defaultLocale || hasLocale(options, locale)
      ? locale
      : routing.defaultLocale;
  return {
    canonical: localizedPath(path, canonicalLocale),
    languages: hreflangLanguages(path, options),
  };
}

/** URL absoluta y localizada (para JSON-LD, que exige URLs completas). */
export function absoluteLocalized(base: string, path: string, locale: string): string {
  const clean = (base || "").replace(/\/$/, "");
  return `${clean}${localizedPath(path, locale)}`;
}
