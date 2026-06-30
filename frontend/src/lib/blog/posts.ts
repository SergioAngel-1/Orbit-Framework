import "server-only";
import { fetchGraphQL } from "@/lib/graphql-client";
import { POSTS_QUERY, POST_BY_SLUG_QUERY, POST_SLUGS_QUERY } from "@/lib/queries";
import type { WPPost, WPPostDetail, PostsPage } from "@/types/wordpress";

const BLOG_REVALIDATE = 300;

/** Índice del blog: listado paginado de posts publicados. */
export async function getPosts(options: { first?: number; after?: string } = {}): Promise<PostsPage> {
  const data = await fetchGraphQL<{
    posts: { pageInfo: PostsPage["pageInfo"]; nodes: WPPost[] };
  }>(POSTS_QUERY, {
    variables: { first: options.first ?? 9, after: options.after },
    revalidate: BLOG_REVALIDATE,
    tags: ["posts"],
  });
  return { posts: data.posts.nodes, pageInfo: data.posts.pageInfo };
}

/** Post individual por slug (null si no existe). */
export async function getPostBySlug(slug: string): Promise<WPPostDetail | null> {
  const data = await fetchGraphQL<{ post: WPPostDetail | null }>(POST_BY_SLUG_QUERY, {
    variables: { slug },
    revalidate: BLOG_REVALIDATE,
    tags: ["posts"],
  });
  return data.post ?? null;
}

/** Slugs publicados (+ fecha de modificación) para SSG y sitemap. */
export async function getPostSlugs(): Promise<{ slug: string; modified: string | null }[]> {
  try {
    const data = await fetchGraphQL<{
      posts: { nodes: { slug: string; modified: string | null }[] };
    }>(POST_SLUGS_QUERY, {
      variables: { first: 100 },
      revalidate: BLOG_REVALIDATE,
      tags: ["posts"],
    });
    return data.posts.nodes.map((n) => ({ slug: n.slug, modified: n.modified ?? null }));
  } catch {
    return [];
  }
}
