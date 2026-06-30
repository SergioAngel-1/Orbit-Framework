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
  const p = `${prefix}${path}`;
  return p === "" ? "/" : p;
}

/** Mapa hreflang (incluye x-default → locale por defecto). Rutas relativas. */
export function hreflangLanguages(path: string): Record<string, string> {
  const languages: Record<string, string> = {};
  for (const locale of routing.locales) {
    languages[locale] = localizedPath(path, locale);
  }
  languages["x-default"] = localizedPath(path, routing.defaultLocale);
  return languages;
}

/**
 * Bloque `alternates` listo para `generateMetadata` (relativo; Next lo resuelve
 * contra `metadataBase`). `canonical` apunta al locale actual.
 */
export function alternatesFor(path: string, locale: string) {
  return {
    canonical: localizedPath(path, locale),
    languages: hreflangLanguages(path),
  };
}

/** URL absoluta y localizada (para JSON-LD, que exige URLs completas). */
export function absoluteLocalized(base: string, path: string, locale: string): string {
  const clean = (base || "").replace(/\/$/, "");
  return `${clean}${localizedPath(path, locale)}`;
}
