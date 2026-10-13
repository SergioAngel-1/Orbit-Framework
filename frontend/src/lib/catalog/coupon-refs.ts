import "server-only";
import { fetchGraphQL } from "@/lib/graphql-client";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Referencias de catálogo para los cupones públicos.
//
//  WooCommerce aplica la categoría de un cupón a toda su DESCENDENCIA, así que
//  decidir dónde anunciarlo (ficha, categoría, carrito) necesita las categorías
//  de cada producto CON sus ancestros. Sale de WPGraphQL (`ancestors`).
//
//  Fail-soft: sin catálogo no se rompe nada; el cupón solo se anuncia con las
//  categorías directas (WordPress sigue decidiendo si vale al aplicarlo).
// ============================================================================

const CATALOG_REVALIDATE = 300;

interface CategoryNode {
  slug: string;
  ancestors?: { nodes?: { slug: string }[] } | null;
}

const CATEGORY_PATHS_QUERY = /* GraphQL */ `
  query CouponCategoryPaths($first: Int = 100) {
    productCategories(first: $first) {
      nodes {
        slug
        ancestors {
          nodes {
            slug
          }
        }
      }
    }
  }
`;

const CART_PRODUCTS_QUERY = /* GraphQL */ `
  query CouponCartProducts($include: [Int]) {
    products(first: 100, where: { include: $include }) {
      nodes {
        databaseId
        slug
        productCategories {
          nodes {
            slug
            ancestors {
              nodes {
                slug
              }
            }
          }
        }
      }
    }
  }
`;

function withAncestors(category: CategoryNode): string[] {
  return [category.slug, ...(category.ancestors?.nodes ?? []).map((a) => a.slug)];
}

/** Slug de cada categoría → ella y sus ancestros. Vacío si el catálogo no responde. */
export async function getCategoryPaths(): Promise<Map<string, string[]>> {
  try {
    const data = await fetchGraphQL<{ productCategories: { nodes: CategoryNode[] } }>(
      CATEGORY_PATHS_QUERY,
      { revalidate: CATALOG_REVALIDATE, tags: ["products"] },
    );
    return new Map(data.productCategories.nodes.map((c) => [c.slug, withAncestors(c)]));
  } catch (error) {
    logger.warn({
      event: "coupons.category_paths.error",
      err: error instanceof Error ? error.message : error,
    });
    return new Map();
  }
}

/** Identidad de catálogo de un producto del carrito, la que usan los cupones. */
export interface CartProductRef {
  slug: string;
  /** Sus categorías y los ancestros de cada una. */
  categorySlugs: string[];
}

/** Producto (por id) → slug y categorías con ancestros. Vacío si falla. */
export async function getCartProductRefs(
  ids: readonly number[],
): Promise<Map<number, CartProductRef>> {
  const include = [...new Set(ids)].filter((id) => Number.isInteger(id) && id > 0);
  if (include.length === 0) return new Map();
  try {
    const data = await fetchGraphQL<{
      products: {
        nodes: {
          databaseId: number;
          slug: string;
          productCategories?: { nodes?: CategoryNode[] } | null;
        }[];
      };
    }>(CART_PRODUCTS_QUERY, {
      variables: { include },
      revalidate: CATALOG_REVALIDATE,
      tags: ["products"],
    });
    return new Map(
      data.products.nodes.map((p) => [
        p.databaseId,
        {
          slug: p.slug,
          categorySlugs: [
            ...new Set((p.productCategories?.nodes ?? []).flatMap(withAncestors)),
          ],
        },
      ]),
    );
  } catch (error) {
    logger.warn({
      event: "coupons.cart_refs.error",
      err: error instanceof Error ? error.message : error,
    });
    return new Map();
  }
}
