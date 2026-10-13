import "server-only";
import { cache } from "react";
import { fetchGraphQL } from "@/lib/graphql-client";
import {
  AUTHOR_BY_SLUG_QUERY,
  FEATURED_POST_QUERY,
  POSTS_INDEX_QUERY,
  POSTS_QUERY,
  POST_BY_SLUG_QUERY,
  POST_SLUGS_QUERY,
  TAGS_QUERY,
  TAG_BY_SLUG_QUERY,
} from "@/lib/queries";
import { pickLocalized } from "@/lib/i18n/pick-localized";
import { tagUrlSlug } from "@/lib/blog/tag-slug";
import type { PageInfo, WPPost, WPPostDetail, WPTag } from "@/types/wordpress";

const BLOG_REVALIDATE = 300;

/** Tope de páginas de cualquier recorrido por cursor: si el cursor no avanza, no hay bucle. */
const MAX_CURSOR_PAGES = 20;

/** Etiqueta resuelta al idioma de la página, con el valor de `?tag=` en ese idioma. */
export type LocalizedTag = WPTag & { urlSlug: string };

function localizeTag(tag: WPTag, locale: string): LocalizedTag {
  return {
    ...tag,
    name: pickLocalized(tag, "name", locale) ?? tag.name,
    urlSlug: tagUrlSlug(tag as WPTag & Record<string, unknown>, locale),
  };
}

function localizeTags<T extends { tags?: { nodes: WPTag[] } | null }>(
  post: T,
  locale: string,
): T {
  if (!post.tags) return post;
  return {
    ...post,
    tags: { nodes: post.tags.nodes.map((tag) => localizeTag(tag, locale)) },
  };
}

/**
 * Índice del blog: listado paginado de entradas publicadas. `tag`/`author`/
 * `category` (slug) y `search` filtran el listado — los usan las etiquetas, el
 * autor y la categoría clicables de las tarjetas y la ficha, y
 * `/api/blog/posts` para el scroll infinito.
 */
export async function getPosts(
  options: {
    first?: number;
    after?: string;
    /** Slug(s) de WordPress: varios cuando un filtro agrupa etiquetas. */
    tag?: string | string[];
    author?: string;
    category?: string;
    search?: string;
    locale?: string;
  } = {},
): Promise<{ posts: WPPost[]; pageInfo: PageInfo }> {
  const locale = options.locale ?? "es";
  const data = await fetchGraphQL<{
    // Opcional: un CMS vacío no trae `posts`.
    posts?: { pageInfo: PageInfo; nodes: WPPost[] };
  }>(POSTS_QUERY, {
    variables: {
      first: options.first ?? 9,
      after: options.after,
      tag: options.tag
        ? Array.isArray(options.tag)
          ? options.tag
          : [options.tag]
        : undefined,
      author: options.author || undefined,
      category: options.category || undefined,
      search: options.search || undefined,
    },
    revalidate: BLOG_REVALIDATE,
    tags: ["posts"],
  });
  return {
    posts: (data.posts?.nodes ?? []).map((node) => localizeTags(node, locale)),
    pageInfo: data.posts?.pageInfo ?? { hasNextPage: false, endCursor: null },
  };
}

/**
 * Colchón sobre `limit` al pedir la tanda: el orden ya viene por uso, así que
 * las etiquetas de una sola entrada están todas al final y basta con pedir de
 * más para que el filtro de `count > 1` no deje la lista corta.
 */
const TOP_TAGS_SLACK = 30;

/**
 * Las N etiquetas más usadas del blog (la lista colapsable del buscador). El
 * ORDEN lo pone el servidor (ver `TAGS_QUERY`); aquí solo se filtran las de una
 * entrada —no aportan como filtro de descubrimiento— y se recorta.
 */
export async function getTopTags(limit = 30, locale = "es"): Promise<LocalizedTag[]> {
  const data = await fetchGraphQL<{ tags?: { nodes: WPTag[] } }>(TAGS_QUERY, {
    variables: { first: limit + TOP_TAGS_SLACK },
    revalidate: BLOG_REVALIDATE,
    tags: ["posts"],
  });
  const top = (data.tags?.nodes ?? [])
    .filter((tag) => (tag.count ?? 0) > 1)
    .map((tag) => localizeTag(tag, locale));
  // Dos etiquetas que en un idioma comparten traducción comparten URL: se pinta
  // UNA píldora, la primera (la de más uso, porque el orden ya viene por `count`).
  return top
    .filter((tag, i) => top.findIndex((t) => t.urlSlug === tag.urlSlug) === i)
    .slice(0, limit);
}

/**
 * Entrada fijada a mano en Entradas → Post Destacado, o null si no hay
 * selección o la lectura falla (el destacado es un adorno: el listado sigue).
 */
export async function getFeaturedPostOverride(locale = "es"): Promise<WPPost | null> {
  try {
    const data = await fetchGraphQL<{ hweFeaturedPost?: WPPost | null }>(
      FEATURED_POST_QUERY,
      { revalidate: BLOG_REVALIDATE, tags: ["posts"] },
    );
    return data.hweFeaturedPost ? localizeTags(data.hweFeaturedPost, locale) : null;
  } catch {
    return null;
  }
}

/** Etiqueta por slug (encabezado de `/blog?tag=`), null si no existe. */
export async function getTagBySlug(
  slug: string,
  locale = "es",
): Promise<{ name: string; slug: string } | null> {
  const data = await fetchGraphQL<{ tag?: WPTag | null }>(TAG_BY_SLUG_QUERY, {
    variables: { slug },
    revalidate: BLOG_REVALIDATE,
    tags: ["posts"],
  });
  const tag = data.tag;
  return tag
    ? { name: pickLocalized(tag, "name", locale) ?? tag.name, slug: tag.slug }
    : null;
}

/** Autor por slug (encabezado de `/blog?author=`), null si no existe. */
export async function getAuthorBySlug(
  slug: string,
): Promise<{ name: string; slug: string } | null> {
  const data = await fetchGraphQL<{ user?: { name: string; slug: string } | null }>(
    AUTHOR_BY_SLUG_QUERY,
    { variables: { slug }, revalidate: BLOG_REVALIDATE, tags: ["posts"] },
  );
  return data.user ?? null;
}

/** Entrada individual por slug (null si no existe). */
export async function getPostBySlug(
  slug: string,
  locale = "es",
): Promise<WPPostDetail | null> {
  const data = await fetchGraphQL<{ post?: WPPostDetail | null }>(POST_BY_SLUG_QUERY, {
    variables: { slug },
    revalidate: BLOG_REVALIDATE,
    tags: ["posts"],
  });
  return data.post ? localizeTags(data.post, locale) : null;
}

/** Slugs publicados (+ fecha de modificación) para SSG y sitemap, recorridos por cursor. */
export async function getPostSlugs(): Promise<
  { slug: string; modified: string | null }[]
> {
  interface SlugsResponse {
    posts?: {
      pageInfo?: { hasNextPage: boolean; endCursor: string | null };
      nodes: { slug: string; modified: string | null }[];
    };
  }
  try {
    const slugs: { slug: string; modified: string | null }[] = [];
    let cursor: string | null = null;
    for (let page = 0; page < MAX_CURSOR_PAGES; page++) {
      const data: SlugsResponse = await fetchGraphQL<SlugsResponse>(POST_SLUGS_QUERY, {
        variables: { first: 100, after: cursor },
        revalidate: BLOG_REVALIDATE,
        tags: ["posts"],
      });
      slugs.push(
        ...(data.posts?.nodes ?? []).map((n) => ({
          slug: n.slug,
          modified: n.modified ?? null,
        })),
      );
      const { hasNextPage, endCursor } = data.posts?.pageInfo ?? {
        hasNextPage: false,
        endCursor: null,
      };
      if (!hasNextPage || !endCursor) break;
      cursor = endCursor;
    }
    return slugs;
  } catch {
    return [];
  }
}

/** Una fila del archivo del blog: lo justo para una lista de enlaces. */
export interface PostIndexEntry {
  slug: string;
  title: string;
  date: string;
}

/**
 * Índice de TODAS las entradas publicadas, de la más nueva a la más antigua,
 * para el archivo del blog (`/blog/archive`).
 *
 * A diferencia de `getPostSlugs`, un fallo del CMS NO se traga: devolver `[]`
 * dejaría una página de archivo vacía cacheada por ISR como si fuera la buena;
 * quien llama decide cómo mostrarlo.
 */
export const getPostsIndex = cache(async (): Promise<PostIndexEntry[]> => {
  interface IndexResponse {
    // Opcional: un CMS vacío no trae `posts`.
    posts?: {
      pageInfo: { hasNextPage: boolean; endCursor: string | null };
      nodes: PostIndexEntry[];
    };
  }
  const entries: PostIndexEntry[] = [];
  let cursor: string | null = null;
  for (let page = 0; page < MAX_CURSOR_PAGES; page++) {
    const data: IndexResponse = await fetchGraphQL<IndexResponse>(POSTS_INDEX_QUERY, {
      variables: { first: 100, after: cursor },
      revalidate: BLOG_REVALIDATE,
      tags: ["posts"],
    });
    for (const node of data.posts?.nodes ?? []) {
      entries.push({ slug: node.slug, title: node.title, date: node.date });
    }
    const { hasNextPage, endCursor } = data.posts?.pageInfo ?? {
      hasNextPage: false,
      endCursor: null,
    };
    if (!hasNextPage || !endCursor) break;
    cursor = endCursor;
  }
  return entries;
});

/**
 * Fecha de WordPress en ISO 8601 CON zona, para `article:published_time` y el
 * `datePublished` del JSON-LD. `date`/`modified` salen en la hora del sitio sin
 * sufijo (`2026-06-10T16:08:02`), que un consumidor lee como UTC o como su hora
 * local. La variante `*Gmt` está en UTC, así que basta con declararlo. Sin ella,
 * la fecha local tal cual.
 */
export function wpDateIso(gmt: string | null | undefined, local: string): string;
export function wpDateIso(
  gmt: string | null | undefined,
  local: string | null | undefined,
): string | undefined;
export function wpDateIso(
  gmt: string | null | undefined,
  local: string | null | undefined,
): string | undefined {
  if (gmt && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(gmt)) return `${gmt}+00:00`;
  return local ?? undefined;
}
