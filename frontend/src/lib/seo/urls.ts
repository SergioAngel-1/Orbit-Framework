import { routing } from "@/i18n/routing";
import { toPublicPath } from "@/lib/instance/public-paths";

/**
 * Helpers de URL conscientes del locale para SEO (canónicas, hreflang, JSON-LD).
 *
 * Convención del proyecto (`localePrefix: "as-needed"`): el locale por defecto
 * (es) NO lleva prefijo; el resto sí (/en/...). Mantener esto centralizado evita
 * divergencias entre sitemap, metadatos y datos estructurados.
 *
 * `path` es la ruta INTERNA (la carpeta de `app/[locale]/`); `localizedPath` la
 * traduce al tramo público que declare la instancia (`publicPaths`) antes de anteponer el
 * prefijo de idioma. Así canónicas, hreflang, sitemap, JSON-LD, `llms.txt` y los enlaces
 * de los correos salen con la misma URL que la vista.
 */

// El mapa de rutas públicas se valida al cargar `routing.ts`, no aquí (esto lo importa el
// cliente).

/** Ruta relativa con prefijo de locale (vacía → "/"). */
export function localizedPath(path: string, locale: string): string {
  const publicPath = toPublicPath(path, locale);
  const prefix = locale === routing.defaultLocale ? "" : `/${locale}`;
  // La home de un idioma con prefijo es `/en`, no `/en/` (así la sirve next-intl).
  const p = `${prefix}${publicPath === "/" ? "" : publicPath}`;
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
  /**
   * Ruta de la entidad en cada idioma, sin prefijo de locale, cuando el tramo
   * de URL cambia con el idioma (slug traducido). Un idioma sin entrada usa la
   * ruta base.
   */
  paths?: Readonly<Record<string, string>>;
}

function hasLocale(options: AlternatesOptions, locale: string): boolean {
  return !options.locales || options.locales.includes(locale);
}

/** Ruta de la entidad en `locale` (sin prefijo): la suya si la declara, si no la base. */
function pathFor(path: string, locale: string, options: AlternatesOptions): string {
  return options.paths?.[locale] ?? path;
}

/** Mapa hreflang (incluye x-default → locale por defecto). Rutas relativas. */
export function hreflangLanguages(
  path: string,
  options: AlternatesOptions = {},
): Record<string, string> {
  const languages: Record<string, string> = {};
  for (const locale of routing.locales) {
    if (locale !== routing.defaultLocale && !hasLocale(options, locale)) continue;
    languages[locale] = localizedPath(pathFor(path, locale, options), locale);
  }
  languages["x-default"] = localizedPath(
    pathFor(path, routing.defaultLocale, options),
    routing.defaultLocale,
  );
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
    canonical: localizedPath(pathFor(path, canonicalLocale, options), canonicalLocale),
    languages: hreflangLanguages(path, options),
  };
}

/**
 * `paths` de `AlternatesOptions` para una ficha cuyo tramo cambia con el idioma:
 * `entityPaths("/products", { es: "camiseta", en: "t-shirt" })` →
 * `{ es: "/products/camiseta", en: "/products/t-shirt" }`.
 */
export function entityPaths(
  prefix: string,
  urlSlugs: Readonly<Record<string, string>> | undefined,
): Record<string, string> {
  return Object.fromEntries(
    Object.entries(urlSlugs ?? {}).map(([locale, slug]) => [
      locale,
      `${prefix}/${encodeURIComponent(slug)}`,
    ]),
  );
}

/** URL absoluta y localizada (para JSON-LD, que exige URLs completas). */
export function absoluteLocalized(base: string, path: string, locale: string): string {
  const clean = (base || "").replace(/\/$/, "");
  return `${clean}${localizedPath(path, locale)}`;
}
