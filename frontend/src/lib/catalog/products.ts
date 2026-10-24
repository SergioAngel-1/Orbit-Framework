import "server-only";
import { routing } from "@/i18n/routing";
import { fetchLocalizedGraphQL } from "@/lib/i18n/fetch-localized";
import { isUnknownProductError } from "@/lib/catalog/unknown-product";
import {
  pickTranslated,
  secondaryLocales,
  translatedLocales,
} from "@/lib/i18n/translation";
import { localizedUrlSlug, urlSlugsByLocale } from "@/lib/i18n/url-slug";
import { logger } from "@/lib/observability/logger";
import { readProductFields, type RawProductField } from "@/lib/catalog/product-fields";
import { membershipsCatalogEnabled } from "@/lib/memberships/catalog-flag";
import { normalizeMembershipRequirement } from "@/lib/memberships/requirements";
import {
  PRODUCTS_BY_IDS_QUERY,
  PRODUCTS_BY_IDS_QUERY_WITH_MEMBERSHIPS,
  PRODUCTS_BY_SLUGS_QUERY,
  PRODUCTS_BY_SLUGS_QUERY_WITH_MEMBERSHIPS,
  PRODUCTS_QUERY,
  PRODUCTS_QUERY_WITH_MEMBERSHIPS,
  PRODUCT_BY_SLUG_QUERY,
  PRODUCT_BY_SLUG_QUERY_WITH_MEMBERSHIPS,
  PRODUCT_SLUGS_QUERY,
  CATEGORY_BY_SLUG_QUERY,
  CATEGORY_BY_SLUG_QUERY_WITH_MEMBERSHIPS,
  CATEGORIES_QUERY,
  CATEGORIES_QUERY_WITH_MEMBERSHIPS,
} from "@/lib/woocommerce/queries";
import type {
  CatalogProduct,
  ProductDetail,
  ProductsPage,
  ProductCategory,
} from "@/types/catalog";
import { logReadFailure } from "@/lib/observability/read-failure";

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
  image?: {
    sourceUrl: string;
    altText: string | null;
    isReferenceImage?: boolean;
  } | null;
  attributes?: { nodes: RawAttribute[] } | null;
  variations?: { nodes: RawVariation[] } | null;
  reviews?: { averageRating: number; edges: RawReview[] } | null;
  related?: { nodes: RawProductNode[] } | null;
  seoTitle?: string | null;
  seoDescription?: string | null;
  hweFields?: RawProductField[] | null;
  /** Requisito del módulo `memberships`; el campo NO existe con el módulo apagado. */
  hweMembership?: unknown;
}

/**
 * Nodo tal como lo deja `fetchLocalizedGraphQL`: la respuesta de WooGraphQL más
 * las traducciones aplanadas (`name_en`, `short_description_en`, `slug_en`…).
 */
type Localizable<T> = T & Record<string, unknown>;

// ── Normalización ─────────────────────────────────────────────────────────────

/**
 * Producto tal como se pinta en `locale`: nombre y resumen traducidos (con caída
 * al texto nativo) y el tramo de URL del idioma. El slug nativo sigue siendo la
 * identidad (carrito, wishlist, cupones). No arrastra el texto de otros idiomas.
 */
function normalize(rawNode: RawProductNode, locale: string): CatalogProduct {
  const node = rawNode as Localizable<RawProductNode>;
  return {
    id: node.id,
    databaseId: node.databaseId,
    name: pickTranslated(node, "name", locale) ?? node.name,
    slug: node.slug,
    urlSlug: localizedUrlSlug(node, locale),
    type: node.type,
    onSale: Boolean(node.onSale),
    price: node.price ?? null,
    regularPrice: node.regularPrice ?? null,
    salePrice: node.salePrice ?? null,
    stockStatus: node.stockStatus ?? null,
    shortDescription: pickTranslated(node, "shortDescription", locale),
    image: node.image
      ? {
          sourceUrl: node.image.sourceUrl,
          altText: node.image.altText ?? "",
          ...(node.image.isReferenceImage ? { isReference: true } : {}),
        }
      : null,
    membership: normalizeMembershipRequirement(node.hweMembership),
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
  /** Idioma de la página: textos y tramos de URL traducidos. Por defecto, el idioma por defecto. */
  locale?: string;
}

/** Precio del filtro (llega como texto de la URL) → número, o `undefined` si no vale. */
function priceVariable(raw?: string): number | undefined {
  if (raw === undefined || raw.trim() === "") return undefined;
  const value = Number(raw);
  return Number.isFinite(value) && value >= 0 ? value : undefined;
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
      // `ProductsOrderByEnum` no tiene `TITLE`: el nombre del producto es `NAME`.
      return { orderbyField: "NAME", orderbyOrder: "ASC" };
    default:
      return { orderbyField: "DATE", orderbyOrder: "DESC" };
  }
}

// ── Funciones públicas ─────────────────────────────────────────────────────────

export async function getProducts(
  options: ProductFilterParams = {},
): Promise<ProductsPage> {
  const { orderbyField, orderbyOrder } = parseSortVariables(options.sort);
  const locale = options.locale ?? routing.defaultLocale;
  const document = (await membershipsCatalogEnabled())
    ? PRODUCTS_QUERY_WITH_MEMBERSHIPS
    : PRODUCTS_QUERY;
  const data = await fetchLocalizedGraphQL<{
    products: {
      pageInfo: ProductsPage["pageInfo"];
      nodes: Localizable<RawProductNode>[];
    };
  }>(document, locale, {
    variables: {
      first: options.first ?? 12,
      after: options.after,
      search: options.search,
      category: options.category,
      minPrice: priceVariable(options.minPrice),
      maxPrice: priceVariable(options.maxPrice),
      stockStatus: options.stockStatus ? [options.stockStatus] : undefined,
      orderbyField,
      orderbyOrder,
    },
    revalidate: CATALOG_REVALIDATE,
    tags: ["products"],
  });

  return {
    products: data.products.nodes.map((node) => normalize(node, locale)),
    pageInfo: data.products.pageInfo,
  };
}

/**
 * Productos publicados por id, en el MISMO orden que `ids` (WooGraphQL no lo
 * garantiza). Los que no existan o no estén publicados se omiten.
 */
export async function getProductsByIds(
  ids: readonly number[],
  locale: string = routing.defaultLocale,
): Promise<CatalogProduct[]> {
  if (ids.length === 0) return [];
  const document = (await membershipsCatalogEnabled())
    ? PRODUCTS_BY_IDS_QUERY_WITH_MEMBERSHIPS
    : PRODUCTS_BY_IDS_QUERY;
  const data = await fetchLocalizedGraphQL<{
    products: { nodes: Localizable<RawProductNode>[] };
  }>(document, locale, {
    variables: { ids: [...ids], first: ids.length },
    revalidate: CATALOG_REVALIDATE,
    tags: ["products"],
  });
  const byId = new Map(
    (data.products?.nodes ?? []).map((node) => [
      node.databaseId,
      normalize(node, locale),
    ]),
  );
  return ids.flatMap((id) => {
    const product = byId.get(id);
    return product ? [product] : [];
  });
}

/**
 * Productos publicados por slug, en el MISMO orden que `slugs` (WooGraphQL no lo
 * garantiza). Los que no existan o no estén publicados se omiten.
 */
export async function getProductsBySlugs(
  slugs: readonly string[],
  locale: string = routing.defaultLocale,
): Promise<CatalogProduct[]> {
  if (slugs.length === 0) return [];
  const document = (await membershipsCatalogEnabled())
    ? PRODUCTS_BY_SLUGS_QUERY_WITH_MEMBERSHIPS
    : PRODUCTS_BY_SLUGS_QUERY;
  const data = await fetchLocalizedGraphQL<{
    products?: { nodes: Localizable<RawProductNode>[] };
  }>(document, locale, {
    variables: { slugs: [...slugs], first: slugs.length },
    revalidate: CATALOG_REVALIDATE,
    tags: ["products"],
  });
  const bySlug = new Map(
    (data.products?.nodes ?? []).map((node) => [node.slug, normalize(node, locale)]),
  );
  return slugs.flatMap((slug) => {
    const product = bySlug.get(slug);
    return product ? [product] : [];
  });
}

/**
 * Una ficha por su slug NATIVO, en `locale`. Pide las traducciones de TODOS los
 * idiomas del sitio (no solo la del idioma de la página): la ficha declara el
 * hreflang y la canónica de cada uno, y la misma query sirve a cualquier idioma.
 *
 * Null si la ficha no existe. WooGraphQL no devuelve `product: null` ante un slug
 * desconocido: lanza un `UserError` («No product ID was found…») que llega como
 * error de GraphQL (ver `isUnknownProductError`); cualquier otro error se propaga.
 */
export async function getProductBySlug(
  slug: string,
  locale: string = routing.defaultLocale,
): Promise<ProductDetail | null> {
  let data: { product: Localizable<RawProductNode> | null };
  try {
    const document = (await membershipsCatalogEnabled())
      ? PRODUCT_BY_SLUG_QUERY_WITH_MEMBERSHIPS
      : PRODUCT_BY_SLUG_QUERY;
    data = await fetchLocalizedGraphQL<{
      product: Localizable<RawProductNode> | null;
    }>(document, secondaryLocales(), {
      variables: { slug, locale },
      revalidate: CATALOG_REVALIDATE,
      tags: ["products"],
    });
  } catch (error) {
    if (isUnknownProductError(error)) return null;
    throw error;
  }

  const node = data.product;
  if (!node) return null;

  return {
    ...normalize(node, locale),
    description: pickTranslated(node, "description", locale),
    seoTitle: pickTranslated(node, "seoTitle", locale)?.trim() || null,
    seoDescription: pickTranslated(node, "seoDescription", locale)?.trim() || null,
    fields: readProductFields(node.hweFields),
    urlSlugs: urlSlugsByLocale(node),
    locales: translatedLocales(node, "name"),
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
    related: node.related?.nodes.map((related) => normalize(related, locale)) ?? [],
  };
}

/** Una ficha publicada tal como la necesitan el sitemap y el prerender. */
export interface ProductUrlEntry {
  /** Slug nativo (identidad de la ficha). */
  slug: string;
  /** Tramo de URL por idioma del sitio. */
  slugs: Record<string, string>;
  /** Idiomas del sitio en los que la ficha existe de verdad (el por defecto, siempre). */
  locales: string[];
}

/**
 * Fichas publicadas con el slug de cada idioma y en cuáles existen, sin importar
 * el idioma de la página. Un fallo del catálogo da lista vacía y queda en el log:
 * el sitemap sigue con el resto.
 */
export async function getProductUrls(): Promise<ProductUrlEntry[]> {
  try {
    const data = await fetchLocalizedGraphQL<{
      products: { nodes: Localizable<{ slug: string; name: string }>[] };
    }>(PRODUCT_SLUGS_QUERY, secondaryLocales(), {
      variables: { first: 100 },
      revalidate: CATALOG_REVALIDATE,
      tags: ["products"],
    });
    return data.products.nodes.map((node) => ({
      slug: node.slug,
      slugs: urlSlugsByLocale(node),
      locales: translatedLocales(node, "name"),
    }));
  } catch (error) {
    logger.error(
      {
        event: "catalog.product_urls_failed",
        err: error instanceof Error ? error.message : error,
      },
      "No se pudo listar las fichas del catálogo",
    );
    return [];
  }
}

/**
 * Una categoría por su slug NATIVO, en `locale`. Como la ficha, pide las
 * traducciones de todos los idiomas: declara el hreflang de cada uno.
 */
export async function getCategory(
  slug: string,
  locale: string = routing.defaultLocale,
): Promise<{ category: ProductCategory; products: CatalogProduct[] } | null> {
  const document = (await membershipsCatalogEnabled())
    ? CATEGORY_BY_SLUG_QUERY_WITH_MEMBERSHIPS
    : CATEGORY_BY_SLUG_QUERY;
  const data = await fetchLocalizedGraphQL<{
    productCategory:
      | (Localizable<ProductCategory> & {
          hweMembership?: unknown;
          products: { nodes: Localizable<RawProductNode>[] };
        })
      | null;
  }>(document, secondaryLocales(), {
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
      name: pickTranslated(cat, "name", locale) ?? cat.name,
      slug: cat.slug,
      description: pickTranslated(cat, "description", locale),
      seoTitle: pickTranslated(cat, "seoTitle", locale)?.trim() || null,
      seoDescription: pickTranslated(cat, "seoDescription", locale)?.trim() || null,
      urlSlug: localizedUrlSlug(cat, locale),
      urlSlugs: urlSlugsByLocale(cat),
      locales: translatedLocales(cat, "name"),
      membership: normalizeMembershipRequirement(cat.hweMembership),
    },
    products: cat.products.nodes.map((node) => normalize(node, locale)),
  };
}

/**
 * Categorías con el nombre y el tramo de URL de `locale`. Con `allLocales` pide
 * las traducciones de todos los idiomas y añade `urlSlugs` y `locales` (sitemap
 * y llms.txt, que listan cada idioma sin importar el de la página).
 */
export async function getCategories(
  locale: string = routing.defaultLocale,
  options: { allLocales?: boolean } = {},
): Promise<ProductCategory[]> {
  try {
    const document = (await membershipsCatalogEnabled())
      ? CATEGORIES_QUERY_WITH_MEMBERSHIPS
      : CATEGORIES_QUERY;
    const data = await fetchLocalizedGraphQL<{
      productCategories: {
        nodes: Localizable<
          ProductCategory & { count: number; hweMembership?: unknown }
        >[];
      };
    }>(document, options.allLocales ? secondaryLocales() : locale, {
      variables: { first: 50 },
      revalidate: CATALOG_REVALIDATE,
      tags: ["products"],
    });
    return data.productCategories.nodes.map((c) => ({
      id: c.id,
      databaseId: c.databaseId,
      name: pickTranslated(c, "name", locale) ?? c.name,
      slug: c.slug,
      description: pickTranslated(c, "description", locale),
      urlSlug: localizedUrlSlug(c, locale),
      membership: normalizeMembershipRequirement(c.hweMembership),
      ...(options.allLocales
        ? { urlSlugs: urlSlugsByLocale(c), locales: translatedLocales(c, "name") }
        : {}),
    }));
  } catch (error) {
    logReadFailure("catalog.categories_failed", error, {
      message: "No se pudo listar las categorías del catálogo",
    });
    return [];
  }
}

/**
 * Parámetros `{ locale, slug }` de `categories/[slug]` para `generateStaticParams`:
 * cada categoría, solo en los idiomas en que existe y con su tramo de URL. Sin
 * `generateStaticParams` la ruta se renderiza en cada petición (sin ISR, y un 404
 * o un 308 salen con status 200 por el streaming), así que aunque el catálogo no
 * responda (lista vacía) la ruta debe declararlo.
 */
export async function getCategoryStaticParams(): Promise<
  { locale: string; slug: string }[]
> {
  const categories = await getCategories(routing.defaultLocale, { allLocales: true });
  return categories.flatMap((category) =>
    (category.locales ?? []).flatMap((locale) => {
      const slug = category.urlSlugs?.[locale];
      return slug ? [{ locale, slug }] : [];
    }),
  );
}
