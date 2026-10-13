import { routing } from "@/i18n/routing";
import { pickLocalized } from "@/lib/i18n/pick-localized";
import { translatedLocales } from "@/lib/i18n/translation";

// ============================================================================
//  Categorías del blog — lógica PURA (sin `server-only`): la usan las páginas de
//  servidor y también componentes de cliente (el enlace de la entrada, la barra
//  de búsqueda). La lectura del CMS vive en `categories.ts`; juntarlos rompe el
//  build.
// ============================================================================

/**
 * Categorías de WordPress que son un CAJÓN y no un tema: no tienen página
 * propia. «Uncategorized» (o «sin-categoria», según el idioma de la instalación)
 * es el valor por defecto de WordPress. Una instancia con su propia categoría
 * cajón (p. ej. «blog», que llevan casi todas sus entradas) la añade aquí.
 */
const BUCKET_CATEGORIES = new Set(["uncategorized", "sin-categoria"]);

/** ¿Esta categoría tiene página propia (`/blog/category/<slug>`)? */
export function isLandingCategory(slug: string): boolean {
  const clean = slug.trim().toLowerCase();
  return clean !== "" && !BUCKET_CATEGORIES.has(clean);
}

/** Ruta interna hacia una categoría; un cajón vuelve al listado. */
export function blogCategoryHref(slug: string): string {
  return isLandingCategory(slug)
    ? `/blog/category/${encodeURIComponent(slug)}`
    : "/blog";
}

/** Lo que devuelve WPGraphQL para una categoría. */
export interface RawBlogCategory {
  name: string;
  slug: string;
  description?: string | null;
  count?: number | null;
}

export interface BlogCategory {
  name: string;
  slug: string;
  description: string | null;
  /** Entradas publicadas: 0 = la página no se sirve ni entra al sitemap. */
  count: number;
  /**
   * Idiomas del sitio en los que la categoría tiene página de verdad (el por
   * defecto, siempre). Una categoría sin nombre traducido existe en el idioma
   * por defecto y NO se declara en el otro: ni hreflang ni sitemap.
   */
  locales: string[];
}

/**
 * Categoría tal como se pinta en `locale`: nombre y descripción traducidos (con
 * caída al texto nativo). `raw` puede traer `name_<idioma>` y
 * `description_<idioma>` (ver `flattenTranslations`).
 */
export function toBlogCategory(
  node: RawBlogCategory & Record<string, unknown>,
  locale: string = routing.defaultLocale,
): BlogCategory {
  const description = pickLocalized(node, "description", locale)?.trim() || null;
  return {
    name: pickLocalized(node, "name", locale) ?? node.name,
    slug: node.slug,
    description,
    count: node.count ?? 0,
    locales: translatedLocales(node, "name"),
  };
}

/**
 * ¿Se sirve la página propia de esta categoría? Una categoría sin entradas
 * publicadas o un cajón no tiene página: responde 404 y no entra al sitemap. Es
 * el ÚNICO criterio: lo usan la página, el sitemap y los enlaces, para que
 * ninguno anuncie una URL que los otros dos no sirven.
 */
export function categoryPageAvailable(category: BlogCategory | null): boolean {
  if (!category) return false;
  return isLandingCategory(category.slug) && category.count > 0;
}
