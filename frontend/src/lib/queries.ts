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
