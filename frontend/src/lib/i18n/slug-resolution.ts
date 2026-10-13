import "server-only";
import { cache } from "react";
import { fetchGraphQL } from "@/lib/graphql-client";
import { isGraphQLError } from "@/lib/i18n/fetch-localized";
import { logger } from "@/lib/observability/logger";
import { secondaryLocales } from "@/lib/i18n/translation";

// ============================================================================
//  Resolución del tramo de URL de una ficha (entrada o producto) a su slug
//  NATIVO, que es con el que se consulta WordPress.
//
//  En un idioma secundario el tramo es normalmente su slug traducido
//  (`_hwe_slug_<idioma>`); en el idioma por defecto, el nativo. Pero todos los
//  idiomas aceptan el tramo de cualquier otro —el selector de idioma conserva los
//  params de la ruta y hay enlaces viejos—: la ficha resuelve aquí y luego
//  redirige con 308 a su URL canónica en el idioma de la página.
//
//  Los fallos de WordPress NO se atrapan (salvo que la búsqueda no exista, ver
//  `nativeSlugFrom`): convertir un fallo de red en «no existe» hornearía un 404
//  en la caché ISR.
// ============================================================================

export type SlugPostType = "post" | "product" | "product_cat";

const CACHE_TAG: Record<SlugPostType, string> = {
  post: "posts",
  product: "products",
  product_cat: "products",
};

const SLUG_FROM_QUERY = /* GraphQL */ `
  query SlugFrom($postType: String!, $locale: String!, $slug: String!) {
    hweSlugFrom(postType: $postType, locale: $locale, slug: $slug)
  }
`;

/**
 * Slug nativo de la ficha cuyo slug en `locale` es `slug`, o null si ninguna.
 * Si WordPress no tiene la búsqueda (idiomas sin configurar allí), null y un
 * aviso: la ficha se sirve por su slug nativo.
 */
export const nativeSlugFrom = cache(
  async (
    postType: SlugPostType,
    locale: string,
    slug: string,
  ): Promise<string | null> => {
    try {
      const data = await fetchGraphQL<{ hweSlugFrom: string | null }>(SLUG_FROM_QUERY, {
        variables: { postType, locale, slug },
        revalidate: 3600,
        tags: [CACHE_TAG[postType]],
      });
      return data.hweSlugFrom ?? null;
    } catch (error) {
      if (isGraphQLError(error)) {
        logger.error(
          {
            event: "i18n.slug_lookup_unavailable",
            postType,
            locale,
            err: error.message,
          },
          "WordPress no expone hweSlugFrom: se resuelve solo por el slug nativo.",
        );
        return null;
      }
      throw error;
    }
  },
);

/**
 * Busca la ficha que corresponde al tramo `urlSlug` de la ruta.
 *
 * Orden: el slug traducido del idioma de la página, el nativo, y por último el
 * slug traducido de cada uno de los demás idiomas. Devuelve la ficha y su slug
 * nativo, o null.
 */
export async function resolveBySlugParam<T>(
  postType: SlugPostType,
  urlSlug: string,
  locale: string,
  fetchByNative: (nativeSlug: string) => Promise<T | null>,
): Promise<{ item: T; nativeSlug: string } | null> {
  const secondary = secondaryLocales();
  // Idioma de la página primero (si es secundario), luego los demás.
  const ordered = [locale, ...secondary.filter((code) => code !== locale)].filter(
    (code) => secondary.includes(code),
  );

  if (ordered[0] === locale) {
    const own = await nativeSlugFrom(postType, locale, urlSlug);
    if (own) {
      const item = await fetchByNative(own);
      if (item) return { item, nativeSlug: own };
    }
  }

  const direct = await fetchByNative(urlSlug);
  if (direct) return { item: direct, nativeSlug: urlSlug };

  for (const code of ordered) {
    if (code === locale) continue;
    const nativeSlug = await nativeSlugFrom(postType, code, urlSlug);
    if (!nativeSlug) continue;
    const item = await fetchByNative(nativeSlug);
    if (item) return { item, nativeSlug };
  }
  return null;
}
