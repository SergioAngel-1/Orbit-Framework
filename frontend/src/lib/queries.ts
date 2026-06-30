// ============================================================================
//  Definiciones de queries GraphQL reutilizables.
// ============================================================================

/**
 * Obtiene los últimos N posts publicados, ordenados por fecha descendente.
 * Variable: $first (Int) -> número de posts a recuperar.
 */
export const LATEST_POSTS_QUERY = /* GraphQL */ `
  query LatestPosts($first: Int = 5) {
    posts(
      first: $first
      where: { orderby: { field: DATE, order: DESC }, status: PUBLISH }
    ) {
      nodes {
        id
        databaseId
        title
        slug
        date
        excerpt
        author {
          node {
            name
          }
        }
        featuredImage {
          node {
            sourceUrl
            altText
            mediaDetails {
              width
              height
            }
          }
        }
      }
    }
  }
`;

/**
 * Listado de posts paginado (índice del blog). Cursor-based como el catálogo.
 */
export const POSTS_QUERY = /* GraphQL */ `
  query Posts($first: Int = 9, $after: String) {
    posts(
      first: $first
      after: $after
      where: { orderby: { field: DATE, order: DESC }, status: PUBLISH }
    ) {
      pageInfo { hasNextPage endCursor }
      nodes {
        id
        databaseId
        title
        slug
        date
        modified
        excerpt
        author { node { name } }
        featuredImage {
          node { sourceUrl altText mediaDetails { width height } }
        }
      }
    }
  }
`;

/** Post individual por slug (incluye contenido completo). */
export const POST_BY_SLUG_QUERY = /* GraphQL */ `
  query PostBySlug($slug: ID!) {
    post(id: $slug, idType: SLUG) {
      id
      databaseId
      title
      slug
      date
      modified
      excerpt
      content
      author { node { name description url } }
      featuredImage {
        node { sourceUrl altText mediaDetails { width height } }
      }
    }
  }
`;

/** Slugs de posts publicados (para generateStaticParams y sitemap). */
export const POST_SLUGS_QUERY = /* GraphQL */ `
  query PostSlugs($first: Int = 100) {
    posts(first: $first, where: { status: PUBLISH }) {
      nodes { slug modified }
    }
  }
`;
