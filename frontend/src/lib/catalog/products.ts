import "server-only";
import { fetchGraphQL } from "@/lib/graphql-client";
import {
  PRODUCTS_QUERY,
  PRODUCT_BY_SLUG_QUERY,
  PRODUCT_SLUGS_QUERY,
  CATEGORY_BY_SLUG_QUERY,
  CATEGORIES_QUERY,
} from "@/lib/woocommerce/queries";
import type {
  CatalogProduct,
  ProductDetail,
  ProductsPage,
  ProductCategory,
} from "@/types/catalog";

const CATALOG_REVALIDATE = 300;

// ── Tipos internos (forma cruda de WooGraphQL) ───────────────────────────────

interface RawVariation {
  databaseId: number;
  price: string | null;
  regularPrice: string | null;
  salePrice: string | null;
  stockStatus: string | null;
  attributes: { nodes: { name: string; value: string }[] };
}

interface RawAttribute {
  id: string;
  name: string;
  label: string | null;
  variation: boolean | null;
  options: string[];
}

interface RawReview {
  rating: number;
  node: {
    id: string;
    content: string | null;
    date: string | null;
    author: { node: { name: string | null } | null } | null;
  };
}

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
  attributes?: { nodes: RawAttribute[] } | null;
  variations?: { nodes: RawVariation[] } | null;
  reviews?: { averageRating: number; edges: RawReview[] } | null;
  related?: { nodes: RawProductNode[] } | null;
}

// ── Normalización ─────────────────────────────────────────────────────────────

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

// ── Parámetros de filtro ──────────────────────────────────────────────────────

export interface ProductFilterParams {
  search?: string;
  category?: string;
  minPrice?: string;
  maxPrice?: string;
  stockStatus?: "IN_STOCK" | "OUT_OF_STOCK";
  /** "price-asc" | "price-desc" | "date-desc" | "date-asc" | "title-asc" */
  sort?: string;
  after?: string;
  first?: number;
}

function parseSortVariables(sort?: string) {
  switch (sort) {
    case "price-asc":
      return { orderbyField: "PRICE", orderbyOrder: "ASC" };
    case "price-desc":
      return { orderbyField: "PRICE", orderbyOrder: "DESC" };
    case "date-asc":
      return { orderbyField: "DATE", orderbyOrder: "ASC" };
    case "title-asc":
      return { orderbyField: "TITLE", orderbyOrder: "ASC" };
    default:
      return { orderbyField: "DATE", orderbyOrder: "DESC" };
  }
}

// ── Funciones públicas ─────────────────────────────────────────────────────────

export async function getProducts(
  options: ProductFilterParams = {},
): Promise<ProductsPage> {
  const { orderbyField, orderbyOrder } = parseSortVariables(options.sort);
  const data = await fetchGraphQL<{
    products: { pageInfo: ProductsPage["pageInfo"]; nodes: RawProductNode[] };
  }>(PRODUCTS_QUERY, {
    variables: {
      first: options.first ?? 12,
      after: options.after,
      search: options.search,
      category: options.category,
      minPrice: options.minPrice,
      maxPrice: options.maxPrice,
      stockStatus: options.stockStatus ? [options.stockStatus] : undefined,
      orderbyField,
      orderbyOrder,
    },
    revalidate: CATALOG_REVALIDATE,
    tags: ["products"],
  });

  return {
    products: data.products.nodes.map(normalize),
    pageInfo: data.products.pageInfo,
  };
}

export async function getProductBySlug(slug: string): Promise<ProductDetail | null> {
  const data = await fetchGraphQL<{ product: RawProductNode | null }>(
    PRODUCT_BY_SLUG_QUERY,
    { variables: { slug }, revalidate: CATALOG_REVALIDATE, tags: ["products"] },
  );

  const node = data.product;
  if (!node) return null;

  return {
    ...normalize(node),
    description: node.description ?? null,
    attributes:
      node.attributes?.nodes.map((a) => ({
        id: a.id,
        name: a.name,
        label: a.label ?? a.name,
        variation: a.variation ?? false,
        options: a.options,
      })) ?? [],
    variations:
      node.variations?.nodes.map((v) => ({
        databaseId: v.databaseId,
        price: v.price ?? null,
        regularPrice: v.regularPrice ?? null,
        salePrice: v.salePrice ?? null,
        stockStatus: v.stockStatus ?? null,
        attributes: v.attributes.nodes.map((a) => ({ name: a.name, value: a.value })),
      })) ?? [],
    reviews: {
      averageRating: node.reviews?.averageRating ?? 0,
      items: (node.reviews?.edges ?? []).map((e) => ({
        id: e.node.id,
        content: e.node.content ?? "",
        date: e.node.date ?? "",
        rating: e.rating,
        authorName: e.node.author?.node?.name ?? "Anónimo",
      })),
    },
    related: node.related?.nodes.map(normalize) ?? [],
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

export async function getCategories(): Promise<ProductCategory[]> {
  try {
    const data = await fetchGraphQL<{
      productCategories: { nodes: (ProductCategory & { count: number })[] };
    }>(CATEGORIES_QUERY, {
      variables: { first: 50 },
      revalidate: CATALOG_REVALIDATE,
      tags: ["products"],
    });
    return data.productCategories.nodes.map((c) => ({
      id: c.id,
      databaseId: c.databaseId,
      name: c.name,
      slug: c.slug,
      description: c.description,
    }));
  } catch {
    return [];
  }
}
