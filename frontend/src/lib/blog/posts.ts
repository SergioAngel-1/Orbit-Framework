import "server-only";
import { cache } from "react";
import { fetchGraphQL } from "@/lib/graphql-client";
import { fetchLocalizedGraphQL } from "@/lib/i18n/fetch-localized";
import {
  secondaryLocales,
  stripTranslations,
  translatedLocales,
} from "@/lib/i18n/translation";
import { localizedUrlSlug, urlSlugsByLocale } from "@/lib/i18n/url-slug";
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
import type {
  PageInfo,
  WPCategory,
  WPPost,
  WPPostDetail,
  WPTag,
} from "@/types/wordpress";
import { logReadFailure } from "@/lib/observability/read-failure";

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

/** Lo traducido de una categoría de entrada (solo el nombre: el slug no se traduce). */
function localizeCategories<T extends { categories?: { nodes: WPCategory[] } | null }>(
  post: T,
  locale: string,
): T {
  if (!post.categories) return post;
  return {
    ...post,
    categories: {
      nodes: post.categories.nodes.map((category) => ({
        ...category,
        name: pickLocalized(category, "name", locale) ?? category.name,
      })),
    },
  };
}

/**
 * La entrada en el idioma de la página: título, resumen, contenido y SEO (con
 * caída al texto nativo), el tramo de URL del idioma y sus etiquetas y
 * categorías. Quita después el texto de los demás idiomas: el cliente no lo
 * necesita.
 *
 * Con `withLocales` añade los datos del hreflang (tramo de URL por idioma e
 * idiomas en los que existe): solo la ficha, que es la que los declara.
 */
function localizePost<T extends WPPost>(
  post: T,
  locale: string,
  options: { withLocales?: boolean } = {},
): T {
  const raw = post as T & { content?: string | null };
  const localized = {
    ...localizeCategories(localizeTags(post, locale), locale),
    title: pickLocalized(post, "title", locale) ?? post.title,
    excerpt: pickLocalized(post, "excerpt", locale) ?? post.excerpt,
    seoTitle: pickLocalized(post, "seo_title", locale) ?? post.seoTitle ?? null,
    seoDescription:
      pickLocalized(post, "seo_description", locale) ?? post.seoDescription ?? null,
    urlSlug: localizedUrlSlug(post as WPPost & Record<string, unknown>, locale),
    ...("content" in raw
      ? { content: pickLocalized(post, "content", locale) ?? raw.content ?? null }
      : {}),
    ...(options.withLocales
      ? {
          urlSlugs: urlSlugsByLocale(post as WPPost & Record<string, unknown>),
          locales: translatedLocales(post, "title"),
        }
      : {}),
  } as T;
  return stripTranslations(localized);
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
  const data = await fetchLocalizedGraphQL<{
    // Opcional: un CMS vacío no trae `posts`.
    posts?: { pageInfo: PageInfo; nodes: WPPost[] };
  }>(POSTS_QUERY, locale, {
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
    posts: (data.posts?.nodes ?? []).map((node) => localizePost(node, locale)),
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
  const data = await fetchLocalizedGraphQL<{ tags?: { nodes: WPTag[] } }>(
    TAGS_QUERY,
    locale,
    {
      variables: { first: limit + TOP_TAGS_SLACK },
      revalidate: BLOG_REVALIDATE,
      tags: ["posts"],
    },
  );
  const top = (data.tags?.nodes ?? [])
    .filter((tag) => (tag.count ?? 0) > 1)
    .map((tag) => stripTranslations(localizeTag(tag, locale)));
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
    const data = await fetchLocalizedGraphQL<{ hweFeaturedPost?: WPPost | null }>(
      FEATURED_POST_QUERY,
      locale,
      { revalidate: BLOG_REVALIDATE, tags: ["posts"] },
    );
    return data.hweFeaturedPost ? localizePost(data.hweFeaturedPost, locale) : null;
  } catch (error) {
    logReadFailure("blog.featured_post_failed", error, {
      level: "warn",
      message: "No se pudo leer la entrada destacada: el listado sigue sin ella",
    });
    return null;
  }
}

/** Etiqueta por slug (encabezado de `/blog?tag=`), null si no existe. */
export async function getTagBySlug(
  slug: string,
  locale = "es",
): Promise<{ name: string; slug: string } | null> {
  const data = await fetchLocalizedGraphQL<{ tag?: WPTag | null }>(
    TAG_BY_SLUG_QUERY,
    locale,
    {
      variables: { slug },
      revalidate: BLOG_REVALIDATE,
      tags: ["posts"],
    },
  );
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
  // Todos los idiomas, no solo el de la página: la ficha declara el hreflang y
  // la URL canónica de cada uno (y la misma query sirve a cualquier idioma).
  const data = await fetchLocalizedGraphQL<{ post?: WPPostDetail | null }>(
    POST_BY_SLUG_QUERY,
    secondaryLocales(),
    {
      variables: { slug },
      revalidate: BLOG_REVALIDATE,
      tags: ["posts"],
    },
  );
  return data.post ? localizePost(data.post, locale, { withLocales: true }) : null;
}

/** Una entrada publicada tal como la necesita el sitemap y el prerender. */
export interface PostSlugEntry {
  slug: string;
  modified: string | null;
  /** Tramo de URL por idioma del sitio. */
  slugs: Record<string, string>;
  /** Idiomas del sitio en los que la entrada existe de verdad (el por defecto, siempre). */
  locales: string[];
}

/**
 * Slugs publicados (+ fecha de modificación) para SSG y sitemap, recorridos por
 * cursor, con el slug de cada idioma y en cuáles existe la entrada.
 */
export async function getPostSlugs(): Promise<PostSlugEntry[]> {
  interface SlugsResponse {
    posts?: {
      pageInfo?: { hasNextPage: boolean; endCursor: string | null };
      nodes: ({ slug: string; modified: string | null } & Record<string, unknown>)[];
    };
  }
  try {
    const slugs: PostSlugEntry[] = [];
    let cursor: string | null = null;
    for (let page = 0; page < MAX_CURSOR_PAGES; page++) {
      const data: SlugsResponse = await fetchLocalizedGraphQL<SlugsResponse>(
        POST_SLUGS_QUERY,
        secondaryLocales(),
        {
          variables: { first: 100, after: cursor },
          revalidate: BLOG_REVALIDATE,
          tags: ["posts"],
        },
      );
      slugs.push(
        ...(data.posts?.nodes ?? []).map((n) => ({
          slug: n.slug,
          modified: n.modified ?? null,
          slugs: urlSlugsByLocale(n),
          locales: translatedLocales(n, "title"),
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
  } catch (error) {
    logReadFailure("blog.post_slugs_failed", error, {
      message: "No se pudo listar las entradas del blog (sitemap y prerender)",
    });
    return [];
  }
}

/** Una fila del archivo del blog: lo justo para una lista de enlaces. */
export interface PostIndexEntry {
  slug: string;
  title: string;
  date: string;
  /** Tramo de URL en el idioma de la página (ver `blogPostHref`). */
  urlSlug?: string;
}

/**
 * Índice de TODAS las entradas publicadas, de la más nueva a la más antigua,
 * para el archivo del blog (`/blog/archive`).
 *
 * A diferencia de `getPostSlugs`, un fallo del CMS NO se traga: devolver `[]`
 * dejaría una página de archivo vacía cacheada por ISR como si fuera la buena;
 * quien llama decide cómo mostrarlo.
 */
export const getPostsIndex = cache(async (locale = "es"): Promise<PostIndexEntry[]> => {
  interface IndexResponse {
    // Opcional: un CMS vacío no trae `posts`.
    posts?: {
      pageInfo: { hasNextPage: boolean; endCursor: string | null };
      nodes: (PostIndexEntry & Record<string, unknown>)[];
    };
  }
  const entries: PostIndexEntry[] = [];
  let cursor: string | null = null;
  for (let page = 0; page < MAX_CURSOR_PAGES; page++) {
    const data: IndexResponse = await fetchLocalizedGraphQL<IndexResponse>(
      POSTS_INDEX_QUERY,
      locale,
      {
        variables: { first: 100, after: cursor },
        revalidate: BLOG_REVALIDATE,
        tags: ["posts"],
      },
    );
    for (const node of data.posts?.nodes ?? []) {
      entries.push({
        slug: node.slug,
        title: pickLocalized(node, "title", locale) ?? node.title,
        date: node.date,
        urlSlug: localizedUrlSlug(
          node as PostIndexEntry & Record<string, unknown>,
          locale,
        ),
      });
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
