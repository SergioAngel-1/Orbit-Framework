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
    /** Biographical Info del perfil de WordPress. */
    description?: string | null;
    /** Web del autor (campo Website del perfil). */
    url?: string | null;
  } | null;
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
}

/** Post individual con contenido completo (página de detalle). */
export interface WPPostDetail extends WPPost {
  content: string | null;
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
