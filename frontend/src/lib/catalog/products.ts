import "server-only";
import { fetchGraphQL } from "@/lib/graphql-client";
import {
  PRODUCTS_QUERY,
  PRODUCT_BY_SLUG_QUERY,
  PRODUCT_SLUGS_QUERY,
  CATEGORY_BY_SLUG_QUERY,
} from "@/lib/woocommerce/queries";
import type {
  CatalogProduct,
  ProductDetail,
  ProductsPage,
  ProductCategory,
} from "@/types/catalog";

// ============================================================================
//  Lectura del catálogo vía WooGraphQL con ISR.
//  Todas las consultas se etiquetan con "products" para revalidarse on-demand
//  desde el webhook /api/revalidate.
// ============================================================================

const CATALOG_REVALIDATE = 300; // 5 min

/** Forma cruda de un nodo de producto en WooGraphQL. */
interface RawProductNode {
  id: string;
  databaseId: number;
  name: string;
  slug: string;
  type: string;
  onSale?: boolean | null;
  price?: string | null;
  regularPrice?: string | null;
  salePrice?: string | null;
  stockStatus?: string | null;
  shortDescription?: string | null;
  description?: string | null;
  image?: { sourceUrl: string; altText: string | null } | null;
}

function normalize(node: RawProductNode): CatalogProduct {
  return {
    id: node.id,
    databaseId: node.databaseId,
    name: node.name,
    slug: node.slug,
    type: node.type,
    onSale: Boolean(node.onSale),
    price: node.price ?? null,
    regularPrice: node.regularPrice ?? null,
    salePrice: node.salePrice ?? null,
    stockStatus: node.stockStatus ?? null,
    shortDescription: node.shortDescription ?? null,
    image: node.image
      ? { sourceUrl: node.image.sourceUrl, altText: node.image.altText ?? "" }
      : null,
  };
}

export async function getProducts(options: {
  first?: number;
  after?: string;
  search?: string;
}): Promise<ProductsPage> {
  const data = await fetchGraphQL<{
    products: { pageInfo: ProductsPage["pageInfo"]; nodes: RawProductNode[] };
  }>(PRODUCTS_QUERY, {
    variables: {
      first: options.first ?? 12,
      after: options.after,
      search: options.search,
    },
    revalidate: CATALOG_REVALIDATE,
    tags: ["products"],
  });

  return {
    products: data.products.nodes.map(normalize),
    pageInfo: data.products.pageInfo,
  };
}

export async function getProductBySlug(
  slug: string,
): Promise<ProductDetail | null> {
  const data = await fetchGraphQL<{ product: RawProductNode | null }>(
    PRODUCT_BY_SLUG_QUERY,
    { variables: { slug }, revalidate: CATALOG_REVALIDATE, tags: ["products"] },
  );

  if (!data.product) return null;
  return {
    ...normalize(data.product),
    description: data.product.description ?? null,
  };
}

export async function getProductSlugs(): Promise<string[]> {
  try {
    const data = await fetchGraphQL<{ products: { nodes: { slug: string }[] } }>(
      PRODUCT_SLUGS_QUERY,
      { variables: { first: 100 }, revalidate: CATALOG_REVALIDATE, tags: ["products"] },
    );
    return data.products.nodes.map((n) => n.slug);
  } catch {
    return [];
  }
}

export async function getCategory(
  slug: string,
): Promise<{ category: ProductCategory; products: CatalogProduct[] } | null> {
  const data = await fetchGraphQL<{
    productCategory:
      | (ProductCategory & { products: { nodes: RawProductNode[] } })
      | null;
  }>(CATEGORY_BY_SLUG_QUERY, {
    variables: { slug, first: 24 },
    revalidate: CATALOG_REVALIDATE,
    tags: ["products"],
  });

  const cat = data.productCategory;
  if (!cat) return null;

  return {
    category: {
      id: cat.id,
      databaseId: cat.databaseId,
      name: cat.name,
      slug: cat.slug,
      description: cat.description ?? null,
    },
    products: cat.products.nodes.map(normalize),
  };
}
