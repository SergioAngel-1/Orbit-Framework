import "server-only";
import { cache } from "react";
import { fetchGraphQL } from "@/lib/graphql-client";
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

/**
 * Categorías con página propia y al menos una entrada publicada, las más
 * usadas primero. Un fallo de RED se propaga: no es «no hay categorías».
 */
export const getBlogCategories = cache(async (): Promise<BlogCategory[]> => {
  // `categories` opcional: un CMS vacío no lo trae.
  const data = await fetchGraphQL<{ categories?: { nodes: RawBlogCategory[] } }>(
    BLOG_CATEGORIES_QUERY,
    { revalidate: CATEGORY_REVALIDATE, tags: ["posts"] },
  );
  return (data.categories?.nodes ?? [])
    .filter((node) => isLandingCategory(node.slug))
    .map(toBlogCategory)
    .filter((category) => category.count > 0);
});

/**
 * Una categoría por slug; `null` si no existe. NO decide si su página se sirve
 * (cajón, vacía): eso es `categoryPageAvailable`.
 */
export async function getBlogCategory(slug: string): Promise<BlogCategory | null> {
  const data = await fetchGraphQL<{ category?: RawBlogCategory | null }>(
    BLOG_CATEGORY_BY_SLUG_QUERY,
    { variables: { slug }, revalidate: CATEGORY_REVALIDATE, tags: ["posts"] },
  );
  return data.category ? toBlogCategory(data.category) : null;
}
