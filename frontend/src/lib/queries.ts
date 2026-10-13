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
        seoTitle
        seoDescription
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

/** Campos de una tarjeta de entrada (listado, destacado, filtrados). */
const POST_CARD_FIELDS = /* GraphQL */ `
  id
  databaseId
  title
  slug
  date
  modified
  excerpt
  author {
    node {
      name
      slug
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
  # first 50 NO es decorativo: sin argumento WPGraphQL pagina a 10 y recorta la
  # lista SIN avisar.
  tags(first: 50) {
    nodes {
      name
      slug
      count
    }
  }
  categories {
    nodes {
      name
      slug
      count
    }
  }
`;

/**
 * Listado de posts paginado (índice del blog). Cursor-based (estándar
 * WPGraphQL). `tag`/`author`/`category` (slug) y `search` son opcionales:
 * filtran cuando se navega desde una etiqueta, un autor o una categoría
 * clicables, y alimentan el scroll infinito (`/api/blog/posts`).
 */
export const POSTS_QUERY = /* GraphQL */ `
  query Posts(
    $first: Int = 9
    $after: String
    $tag: [String]
    $author: String
    $category: String
    $search: String
  ) {
    posts(
      first: $first
      after: $after
      where: {
        orderby: { field: DATE, order: DESC }
        status: PUBLISH
        tagSlugIn: $tag
        authorName: $author
        categoryName: $category
        search: $search
      }
    ) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        ${POST_CARD_FIELDS}
      }
    }
  }
`;

/**
 * Entrada fijada como destacada del blog (Entradas → Post Destacado,
 * `hwe-featured-post.php`). Null si no hay selección: el frontend usa entonces
 * la más reciente.
 */
export const FEATURED_POST_QUERY = /* GraphQL */ `
  query FeaturedPost {
    hweFeaturedPost {
      ${POST_CARD_FIELDS}
    }
  }
`;

/** Etiqueta por slug (encabezado del listado filtrado). */
export const TAG_BY_SLUG_QUERY = /* GraphQL */ `
  query TagBySlug($slug: ID!) {
    tag(id: $slug, idType: SLUG) {
      name
      slug
    }
  }
`;

/** Autor por slug (encabezado del listado filtrado). */
export const AUTHOR_BY_SLUG_QUERY = /* GraphQL */ `
  query AuthorBySlug($slug: ID!) {
    user(id: $slug, idType: SLUG) {
      name
      slug
    }
  }
`;

/**
 * Categoría por slug: el encabezado del listado filtrado y la página propia de
 * la categoría (`/blog/category/<slug>`). `count` llega null en una vacía.
 */
export const BLOG_CATEGORY_BY_SLUG_QUERY = /* GraphQL */ `
  query BlogCategoryBySlug($slug: ID!) {
    category(id: $slug, idType: SLUG) {
      name
      slug
      description
      count
    }
  }
`;

/**
 * Categorías del blog con entradas publicadas, las más usadas primero: los
 * chips del buscador y las páginas del sitemap. Sin `hideEmpty` saldrían
 * categorías vacías, que no tienen página (se sirven como 404).
 */
export const BLOG_CATEGORIES_QUERY = /* GraphQL */ `
  query BlogCategories($first: Int = 50) {
    categories(first: $first, where: { hideEmpty: true, orderby: COUNT, order: DESC }) {
      nodes {
        name
        slug
        description
        count
      }
    }
  }
`;

/**
 * Índice ligero de TODAS las entradas publicadas para el archivo del blog
 * (`/blog/archive`): solo lo necesario para una lista de enlaces, para poder
 * pedirlas todas en pocas páginas.
 */
export const POSTS_INDEX_QUERY = /* GraphQL */ `
  query PostsIndex($first: Int = 100, $after: String) {
    posts(
      first: $first
      after: $after
      where: { status: PUBLISH, orderby: { field: DATE, order: DESC } }
    ) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        slug
        title
        date
      }
    }
  }
`;

/**
 * Etiquetas publicadas con su `count`, ORDENADAS POR USO en el servidor. El
 * `orderby: COUNT` no es un lujo: sin él WPGraphQL devuelve los términos por
 * NOMBRE y la primera tanda no sería el top sino «de la A a la B». Con el orden
 * en el servidor, `first` deja de ser una lotería.
 */
export const TAGS_QUERY = /* GraphQL */ `
  query Tags($first: Int = 60) {
    tags(first: $first, where: { hideEmpty: true, orderby: COUNT, order: DESC }) {
      nodes {
        name
        slug
        count
      }
    }
  }
`;

/** Entrada individual por slug (incluye contenido completo). */
export const POST_BY_SLUG_QUERY = /* GraphQL */ `
  query PostBySlug($slug: ID!) {
    post(id: $slug, idType: SLUG) {
      id
      databaseId
      title
      slug
      date
      modified
      # En UTC y sin sufijo de zona; date/modified van en la hora del sitio,
      # también sin zona, y como fecha publicada son ambiguas en N horas.
      dateGmt
      modifiedGmt
      excerpt
      content
      seoTitle
      seoDescription
      author {
        node {
          name
          slug
          description
          url
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
      tags(first: 50) {
        nodes {
          name
          slug
          count
        }
      }
      categories {
        nodes {
          name
          slug
          count
        }
      }
    }
  }
`;

/** Slugs de posts publicados (para generateStaticParams y sitemap). */
export const POST_SLUGS_QUERY = /* GraphQL */ `
  query PostSlugs($first: Int = 100, $after: String) {
    posts(first: $first, after: $after, where: { status: PUBLISH }) {
      pageInfo {
        hasNextPage
        endCursor
      }
      nodes {
        slug
        modified
      }
    }
  }
`;
