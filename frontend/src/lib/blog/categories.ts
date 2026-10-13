import "server-only";
import { cache } from "react";
import { routing } from "@/i18n/routing";
import { fetchLocalizedGraphQL } from "@/lib/i18n/fetch-localized";
import { secondaryLocales } from "@/lib/i18n/translation";
import { BLOG_CATEGORIES_QUERY, BLOG_CATEGORY_BY_SLUG_QUERY } from "@/lib/queries";
import {
  isLandingCategory,
  toBlogCategory,
  type BlogCategory,
  type RawBlogCategory,
} from "@/lib/blog/category-format";

// ============================================================================
//  Categorías del blog con página propia — lectura del CMS (server-only). La
//  lógica pura, que también usan los componentes de cliente, está en
//  `category-format.ts`.
// ============================================================================

const CATEGORY_REVALIDATE = 300;

type RawCategory = RawBlogCategory & Record<string, unknown>;

/**
 * Categorías con página propia y al menos una entrada publicada, las más
 * usadas primero, con el nombre y la descripción de `locale`. Se piden las
 * traducciones de todos los idiomas: `locales` de cada una (qué idiomas tienen
 * su página) sale de ahí. Un fallo de RED se propaga: no es «no hay categorías».
 */
export const getBlogCategories = cache(
  async (locale: string = routing.defaultLocale): Promise<BlogCategory[]> => {
    // `categories` opcional: un CMS vacío no lo trae.
    const data = await fetchLocalizedGraphQL<{ categories?: { nodes: RawCategory[] } }>(
      BLOG_CATEGORIES_QUERY,
      secondaryLocales(),
      { revalidate: CATEGORY_REVALIDATE, tags: ["posts"] },
    );
    return (data.categories?.nodes ?? [])
      .filter((node) => isLandingCategory(node.slug))
      .map((node) => toBlogCategory(node, locale))
      .filter((category) => category.count > 0);
  },
);

/**
 * Una categoría por slug, en `locale`; `null` si no existe. NO decide si su
 * página se sirve (cajón, vacía): eso es `categoryPageAvailable`.
 */
export async function getBlogCategory(
  slug: string,
  locale: string = routing.defaultLocale,
): Promise<BlogCategory | null> {
  const data = await fetchLocalizedGraphQL<{ category?: RawCategory | null }>(
    BLOG_CATEGORY_BY_SLUG_QUERY,
    secondaryLocales(),
    { variables: { slug }, revalidate: CATEGORY_REVALIDATE, tags: ["posts"] },
  );
  return data.category ? toBlogCategory(data.category, locale) : null;
}
