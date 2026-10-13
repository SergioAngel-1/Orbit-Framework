// ============================================================================
//  Queries de catálogo (WooGraphQL).
//
//  `Product` es una interfaz; los campos de precio/stock viven en los tipos
//  concretos (SimpleProduct / VariableProduct), por eso usamos fragmentos
//  inline. Otros tipos (grouped/external) devolverán esos campos como null.
// ============================================================================

const PRODUCT_CARD_FRAGMENT = /* GraphQL */ `
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

export const PRODUCTS_QUERY = /* GraphQL */ `
  ${PRODUCT_CARD_FRAGMENT}
  query Products(
    $first: Int = 12
    $after: String
    $search: String
    $category: String
    $minPrice: String
    $maxPrice: String
    $stockStatus: [ProductStockStatusEnum]
    $orderbyField: ProductsOrderbyEnum = DATE
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

/** Productos publicados por id (p. ej. los del ranking de más vendidos). */
export const PRODUCTS_BY_IDS_QUERY = /* GraphQL */ `
  ${PRODUCT_CARD_FRAGMENT}
  query ProductsByIds($ids: [Int], $first: Int = 12) {
    products(first: $first, where: { include: $ids, status: "publish" }) {
      nodes {
        ...ProductCard
      }
    }
  }
`;

/** Productos publicados por slug (los enlazados desde el cuerpo de una entrada). */
export const PRODUCTS_BY_SLUGS_QUERY = /* GraphQL */ `
  ${PRODUCT_CARD_FRAGMENT}
  query ProductsBySlugs($slugs: [String], $first: Int = 12) {
    products(first: $first, where: { slugIn: $slugs, status: "publish" }) {
      nodes {
        ...ProductCard
      }
    }
  }
`;

export const PRODUCT_BY_SLUG_QUERY = /* GraphQL */ `
  ${PRODUCT_CARD_FRAGMENT}
  query ProductBySlug($slug: ID!) {
    product(id: $slug, idType: SLUG) {
      id databaseId name slug type
      seoTitle seoDescription
      # @translation Product
      image { sourceUrl altText isReferenceImage }
      ... on SimpleProduct {
        onSale price regularPrice salePrice stockStatus
        shortDescription description
        ${REVIEWS_FRAGMENT}
        related(first: 4) { nodes { ...ProductCard } }
      }
      ... on VariableProduct {
        onSale price regularPrice salePrice stockStatus
        shortDescription description
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

export const PRODUCT_SLUGS_QUERY = /* GraphQL */ `
  query ProductSlugs($first: Int = 100) {
    products(first: $first, where: { status: "publish" }) {
      nodes {
        slug
        name
        # @translation Product: name slug
      }
    }
  }
`;

export const CATEGORY_BY_SLUG_QUERY = /* GraphQL */ `
  ${PRODUCT_CARD_FRAGMENT}
  query CategoryBySlug($slug: ID!, $first: Int = 12) {
    productCategory(id: $slug, idType: SLUG) {
      id
      databaseId
      name
      slug
      description
      seoTitle
      seoDescription
      # @translation ProductCategory
      products(first: $first) {
        nodes {
          ...ProductCard
        }
      }
    }
  }
`;

export const CATEGORIES_QUERY = /* GraphQL */ `
  query Categories($first: Int = 50) {
    productCategories(first: $first, where: { hideEmpty: true }) {
      nodes {
        id
        databaseId
        name
        slug
        count
        # @translation ProductCategory: name description slug
      }
    }
  }
`;
