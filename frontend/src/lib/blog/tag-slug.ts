import { routing } from "@/i18n/routing";
import { pickLocalized } from "@/lib/i18n/pick-localized";

// ============================================================================
//  Slug de URL de una etiqueta del blog, por idioma.
//
//  El filtro del blog es un query parameter (`/blog?tag=<slug>`) sobre una vista
//  `noindex`, así que no hay canónica, hreflang ni sitemap que mantener:
//  traducirlo es solo cuestión de que la URL se lea en el idioma de quien la
//  ve. En un idioma secundario el valor sale del NOMBRE traducido de la
//  etiqueta (`name_<idioma>`, ver `pickLocalized`), no de un segundo slug
//  guardado en WordPress: traducir la etiqueta en wp-admin ya cambia su URL, sin
//  otro campo que mantener en paralelo. Sin traducción, el slug de WordPress.
//
//  Módulo PURO: lo usan `lib/blog/posts.ts` (servidor) y sus tests.
// ============================================================================

/** `"Summer travel tips"` → `"summer-travel-tips"`. */
export function slugifyTagName(name: string): string {
  return name
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

/**
 * Valor de `?tag=` para una etiqueta en `locale`. En el idioma por defecto es el
 * slug de WordPress. En otro, el nombre traducido hecho slug; sin traducción (o
 * si el nombre no deja nada utilizable), el slug de WordPress.
 */
export function tagUrlSlug(
  tag: { slug: string; name?: string } & Record<string, unknown>,
  locale: string,
): string {
  if (locale === routing.defaultLocale) return tag.slug;
  const translated = pickLocalized(tag, "name", locale);
  if (!translated || translated === tag.name) return tag.slug;
  return slugifyTagName(translated) || tag.slug;
}
