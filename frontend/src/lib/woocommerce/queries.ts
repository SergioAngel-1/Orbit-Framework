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

export const PRODUCTS_QUERY = /* GraphQL */ `
  ${PRODUCT_CARD_FRAGMENT}
  query Products($first: Int = 12, $after: String, $search: String) {
    products(
      first: $first
      after: $after
      where: {
        search: $search
        status: "publish"
        orderby: { field: DATE, order: DESC }
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

export const PRODUCT_BY_SLUG_QUERY = /* GraphQL */ `
  query ProductBySlug($slug: ID!) {
    product(id: $slug, idType: SLUG) {
      id
      databaseId
      name
      slug
      type
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
        description
      }
      ... on VariableProduct {
        onSale
        price
        regularPrice
        salePrice
        stockStatus
        shortDescription
        description
      }
    }
  }
`;

export const PRODUCT_SLUGS_QUERY = /* GraphQL */ `
  query ProductSlugs($first: Int = 100) {
    products(first: $first, where: { status: "publish" }) {
      nodes {
        slug
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
      products(first: $first) {
        nodes {
          ...ProductCard
        }
      }
    }
  }
`;
