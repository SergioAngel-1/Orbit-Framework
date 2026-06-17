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
  } | null;
}

export interface WPPost {
  id: string;
  databaseId: number;
  title: string;
  slug: string;
  date: string;
  excerpt: string;
  featuredImage: WPFeaturedImage | null;
  author: WPAuthor | null;
}

export interface PostsQueryResponse {
  posts: {
    nodes: WPPost[];
  };
}
