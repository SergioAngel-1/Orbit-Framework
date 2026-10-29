// ============================================================================
//  Tipos TypeScript que modelan la respuesta de WPGraphQL.
//  Solo se incluyen los campos que el frontend consume realmente.
// ============================================================================

export interface WPImageNode {
  sourceUrl: string;
  altText: string;
  mediaDetails?: {
    width: number;
    height: number;
  } | null;
}

export interface WPFeaturedImage {
  node: WPImageNode | null;
}

export interface WPAuthor {
  node: {
    name: string;
    /** Slug del usuario: el filtro `/blog?author=`. */
    slug?: string | null;
    /** Biographical Info del perfil de WordPress. */
    description?: string | null;
    /** Web del autor (campo Website del perfil). */
    url?: string | null;
  } | null;
}

/** Etiqueta de una entrada. `count` = entradas publicadas con ella. */
export interface WPTag {
  name: string;
  slug: string;
  count?: number | null;
}

/** Categoría de una entrada. `count` llega null en una vacía. */
export interface WPCategory {
  name: string;
  slug: string;
  count?: number | null;
}

export interface WPPost {
  id: string;
  databaseId: number;
  title: string;
  slug: string;
  date: string;
  modified?: string | null;
  excerpt: string;
  featuredImage: WPFeaturedImage | null;
  author: WPAuthor | null;
  /** Override de hwe-post-seo.php. Null = usar título/resumen nativos. */
  seoTitle?: string | null;
  seoDescription?: string | null;
  /** Controles de indexación de la caja SEO (ver `lib/seo/entity-index.ts`). */
  seoNoindex?: boolean | null;
  seoCanonical?: string | null;
  tags?: { nodes: WPTag[] } | null;
  categories?: { nodes: WPCategory[] } | null;
  /** Tramo de URL en el idioma de la página (slug traducido si lo hay). Ver `blogPostHref`. */
  urlSlug?: string;
  /** Tramo de URL por idioma del sitio (hreflang y canónica de la ficha). */
  urlSlugs?: Record<string, string>;
  /** Idiomas del sitio en los que la entrada existe de verdad (el por defecto, siempre). */
  locales?: string[];
}

/** Post individual con contenido completo (página de detalle). */
export interface WPPostDetail extends WPPost {
  content: string | null;
  /** Fecha y hora de publicación y de modificación en UTC, sin sufijo de zona. */
  dateGmt?: string | null;
  modifiedGmt?: string | null;
}

export interface PostsQueryResponse {
  posts: {
    nodes: WPPost[];
  };
}

export interface PageInfo {
  hasNextPage: boolean;
  endCursor: string | null;
}

export interface PostsPage {
  posts: WPPost[];
  pageInfo: PageInfo;
}
