import { routing } from "@/i18n/routing";
import { pickLocalized } from "@/lib/i18n/pick-localized";

// ============================================================================
//  Slug de URL de una ficha (entrada, producto, categoría) por idioma.
//
//  El slug NATIVO (`post_name`) es la identidad de la ficha —carrito, wishlist,
//  overrides— y es el tramo de la URL en el idioma por defecto. En un idioma
//  secundario, si la ficha trae su slug traducido (`slug_<idioma>`, meta
//  `_hwe_slug_<idioma>`) la URL usa ese; si no, el nativo.
//
//  Las capas de datos resuelven `urlSlug` con esto, igual que resuelven
//  `name`/`title` con `pickLocalized`, así que los helpers de href no necesitan
//  el idioma.
//
//  Módulo PURO: lo usan servidor y cliente.
// ============================================================================

type WithSlug = { slug: string } & Record<string, unknown>;

/** Tramo de URL de la ficha en `locale`. */
export function localizedUrlSlug(entity: WithSlug, locale: string): string {
  return pickLocalized(entity, "slug", locale) ?? entity.slug;
}

/** Tramo de URL de la ficha en cada idioma del sitio (hreflang y sitemap). */
export function urlSlugsByLocale(entity: WithSlug): Record<string, string> {
  return Object.fromEntries(
    routing.locales.map((locale) => [locale, localizedUrlSlug(entity, locale)]),
  );
}
