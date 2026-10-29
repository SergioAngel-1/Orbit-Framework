// ============================================================================
//  Queries de catálogo (WooGraphQL).
//
//  `Product` es una interfaz; los campos de precio/stock viven en los tipos
//  concretos (SimpleProduct / VariableProduct), por eso usamos fragmentos
//  inline. Otros tipos (grouped/external) devolverán esos campos como null.
//
//  El requisito de membresía (`hweMembership`) solo existe en el esquema con el
//  módulo activo. Pedirlo con el módulo apagado haría fallar TODA la consulta,
//  así que cada documento tiene dos variantes y el lector elige con
//  `membershipsCatalogEnabled()`. Las constantes sin sufijo son la variante SIN
//  el campo (la del framework por defecto).
// ============================================================================

/** Campos del requisito de membresía de un producto. */
const PRODUCT_MEMBERSHIP_FIELDS = /* GraphQL */ `
  hweMembership {
    requiredLevel {
      slug
      rank
      name
    }
    opensAt
    locked
  }
`;

/** Campos del requisito de membresía de una categoría. */
const CATEGORY_MEMBERSHIP_FIELDS = /* GraphQL */ `
  hweMembership {
    requiredLevel {
      slug
      rank
      name
    }
    opensAt
    locked
  }
`;

function productCardFragment(withMemberships: boolean): string {
  return /* GraphQL */ `
  fragment ProductCard on Product {
    id
    databaseId
    name
    slug
    type
    # @translation Product: name shortDescription slug
    image {
      sourceUrl
      altText
    }
    ${withMemberships ? PRODUCT_MEMBERSHIP_FIELDS : ""}
    ... on SimpleProduct {
      onSale
      price
      regularPrice
      salePrice
      stockStatus
      shortDescription
    }
    ... on VariableProduct {
      onSale
      price
      regularPrice
      salePrice
      stockStatus
      shortDescription
    }
  }
`;
}

const REVIEWS_FRAGMENT = /* GraphQL */ `
  reviews(first: 10) {
    averageRating
    edges {
      rating
      node {
        id
        content
        date
        author {
          node { name }
        }
      }
    }
  }
`;

// ---------------------------------------------------------------------------
// QUERIES PÚBLICAS
// ---------------------------------------------------------------------------

// Tipos de WooGraphQL ≥ 1.0 (el 1.0 renombró `ProductStockStatusEnum` →
// `StockStatusEnum` y `ProductsOrderbyEnum` → `ProductsOrderByEnum`, y los
// precios del filtro son `Float`). Lo fija `tests/unit/woographql-contract.test.ts`.
function productsQuery(withMemberships: boolean): string {
  return /* GraphQL */ `
    ${productCardFragment(withMemberships)}
    query Products(
      $first: Int = 12
      $after: String
      $search: String
      $category: String
      $minPrice: Float
      $maxPrice: Float
      $stockStatus: [StockStatusEnum]
      $orderbyField: ProductsOrderByEnum = DATE
      $orderbyOrder: OrderEnum = DESC
    ) {
      products(
        first: $first
        after: $after
        where: {
          search: $search
          category: $category
          minPrice: $minPrice
          maxPrice: $maxPrice
          stockStatus: $stockStatus
          status: "publish"
          orderby: [{ field: $orderbyField, order: $orderbyOrder }]
        }
      ) {
        pageInfo {
          hasNextPage
          endCursor
        }
        nodes {
          ...ProductCard
        }
      }
    }
  `;
}

export const PRODUCTS_QUERY = productsQuery(false);
export const PRODUCTS_QUERY_WITH_MEMBERSHIPS = productsQuery(true);

/** Productos publicados por id (p. ej. los del ranking de más vendidos). */
function productsByIdsQuery(withMemberships: boolean): string {
  return /* GraphQL */ `
    ${productCardFragment(withMemberships)}
    query ProductsByIds($ids: [Int], $first: Int = 12) {
      products(first: $first, where: { include: $ids, status: "publish" }) {
        nodes {
          ...ProductCard
        }
      }
    }
  `;
}

export const PRODUCTS_BY_IDS_QUERY = productsByIdsQuery(false);
export const PRODUCTS_BY_IDS_QUERY_WITH_MEMBERSHIPS = productsByIdsQuery(true);

/** Productos publicados por slug (los enlazados desde el cuerpo de una entrada). */
function productsBySlugsQuery(withMemberships: boolean): string {
  return /* GraphQL */ `
    ${productCardFragment(withMemberships)}
    query ProductsBySlugs($slugs: [String], $first: Int = 12) {
      products(first: $first, where: { slugIn: $slugs, status: "publish" }) {
        nodes {
          ...ProductCard
        }
      }
    }
  `;
}

export const PRODUCTS_BY_SLUGS_QUERY = productsBySlugsQuery(false);
export const PRODUCTS_BY_SLUGS_QUERY_WITH_MEMBERSHIPS = productsBySlugsQuery(true);

function productBySlugQuery(withMemberships: boolean): string {
  return /* GraphQL */ `
  ${productCardFragment(withMemberships)}
  query ProductBySlug($slug: ID!, $locale: String) {
    product(id: $slug, idType: SLUG) {
      id databaseId name slug type
      seoTitle seoDescription
      # GTIN validado por el mu-plugin hwe-product-seo (null sin código).
      hweGtin
      # Campos de producto declarados por la instancia (hwe-product-fields), ya en el idioma.
      hweFields(locale: $locale) { key value }
      # @translation Product
      image { sourceUrl altText isReferenceImage }
      ${withMemberships ? PRODUCT_MEMBERSHIP_FIELDS : ""}
      ... on SimpleProduct {
        onSale price regularPrice salePrice stockStatus sku
        shortDescription description
        galleryImages(first: 8) { nodes { sourceUrl } }
        ${REVIEWS_FRAGMENT}
        related(first: 4) { nodes { ...ProductCard } }
      }
      ... on VariableProduct {
        onSale price regularPrice salePrice stockStatus sku
        shortDescription description
        galleryImages(first: 8) { nodes { sourceUrl } }
        attributes {
          nodes { id name label variation options }
        }
        variations(first: 50) {
          nodes {
            databaseId price regularPrice salePrice stockStatus
            attributes { nodes { name value } }
          }
        }
        ${REVIEWS_FRAGMENT}
        related(first: 4) { nodes { ...ProductCard } }
      }
    }
  }
`;
}

export const PRODUCT_BY_SLUG_QUERY = productBySlugQuery(false);
export const PRODUCT_BY_SLUG_QUERY_WITH_MEMBERSHIPS = productBySlugQuery(true);

export const PRODUCT_SLUGS_QUERY = /* GraphQL */ `
  query ProductSlugs($first: Int = 100, $after: String) {
    products(first: $first, after: $after, where: { status: "publish" }) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        slug
        name
        # Fecha real de la última edición: el lastmod del sitemap.
        modified
        # Visibilidad de catálogo de WooCommerce: HIDDEN no se anuncia en el sitemap.
        catalogVisibility
        # @translation Product: name slug
      }
    }
  }
`;

function categoryBySlugQuery(withMemberships: boolean): string {
  return /* GraphQL */ `
  ${productCardFragment(withMemberships)}
  query CategoryBySlug($slug: ID!, $first: Int = 12) {
    productCategory(id: $slug, idType: SLUG) {
      id
      databaseId
      name
      slug
      description
      seoTitle
      seoDescription
      ${withMemberships ? CATEGORY_MEMBERSHIP_FIELDS : ""}
      # @translation ProductCategory
      products(first: $first) {
        nodes {
          ...ProductCard
        }
      }
    }
  }
`;
}

export const CATEGORY_BY_SLUG_QUERY = categoryBySlugQuery(false);
export const CATEGORY_BY_SLUG_QUERY_WITH_MEMBERSHIPS = categoryBySlugQuery(true);

function categoriesQuery(withMemberships: boolean): string {
  return /* GraphQL */ `
  query Categories($first: Int = 50) {
    productCategories(first: $first, where: { hideEmpty: true }) {
      nodes {
        id
        databaseId
        name
        slug
        count
        ${withMemberships ? CATEGORY_MEMBERSHIP_FIELDS : ""}
        # @translation ProductCategory: name description slug
      }
    }
  }
`;
}

export const CATEGORIES_QUERY = categoriesQuery(false);
export const CATEGORIES_QUERY_WITH_MEMBERSHIPS = categoriesQuery(true);
