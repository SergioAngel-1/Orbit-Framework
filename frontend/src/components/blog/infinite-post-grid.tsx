"use client";
import { useEffect, useEffectEvent, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { PostCard } from "./post-card";
import type { PageInfo, WPPost } from "@/types/wordpress";

interface Props {
  initialPosts: WPPost[];
  initialPageInfo: PageInfo;
  locale: string;
  filters: { tag?: string; author?: string; category?: string; search?: string };
}

const BATCH = 9;

/**
 * Grilla del índice del blog con scroll infinito vía IntersectionObserver — el
 * mismo patrón que `components/products/infinite-product-grid.tsx`, con
 * paginación por cursor real (un blog puede tener cientos de entradas, no se
 * precargan todas).
 *
 * El HTML inicial ya trae la primera tanda renderizada por el servidor, así que
 * el contenido es indexable sin ejecutar JS; el enlace al archivo del blog
 * (`/blog/archive`) cubre el resto para quien no hace scroll.
 */
export function InfinitePostGrid({
  initialPosts,
  initialPageInfo,
  locale,
  filters,
}: Props) {
  const t = useTranslations("blog");
  const [posts, setPosts] = useState(initialPosts);
  const [pageInfo, setPageInfo] = useState(initialPageInfo);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const sentinelRef = useRef<HTMLDivElement>(null);

  // Un filtro nuevo llega como props desde el servidor: la grilla acumulada se
  // sustituye por la primera página del nuevo listado. Va como AJUSTE EN RENDER
  // y no como efecto (el patrón documentado para «resetear estado cuando cambia
  // una prop»): con un efecto se pinta un frame con las entradas del filtro
  // anterior antes de corregirse.
  const [lastInitial, setLastInitial] = useState(initialPosts);
  if (lastInitial !== initialPosts) {
    setLastInitial(initialPosts);
    setPosts(initialPosts);
    setPageInfo(initialPageInfo);
    setFailed(false);
  }

  async function loadMore() {
    setLoading(true);
    try {
      const params = new URLSearchParams();
      if (filters.tag) params.set("tag", filters.tag);
      if (filters.author) params.set("author", filters.author);
      if (filters.category) params.set("category", filters.category);
      if (filters.search) params.set("search", filters.search);
      params.set("locale", locale);
      if (pageInfo.endCursor) params.set("after", pageInfo.endCursor);
      params.set("first", String(BATCH));

      const res = await fetch(`/api/blog/posts?${params}`);
      if (!res.ok) throw new Error(String(res.status));
      const data = (await res.json()) as { posts: WPPost[]; pageInfo: PageInfo };
      setPosts((prev) => [...prev, ...data.posts]);
      setPageInfo(data.pageInfo);
    } catch {
      // Sin esto el observador volvería a pedir en bucle contra un fallo: se
      // detiene y deja un botón para reintentar a mano.
      setFailed(true);
    } finally {
      setLoading(false);
    }
  }

  // Lee el estado más reciente (cursor, filtros) sin que el observador se
  // recree en cada render.
  const onLoadMore = useEffectEvent(loadMore);

  useEffect(() => {
    if (!pageInfo.hasNextPage || loading || failed) return;
    const sentinel = sentinelRef.current;
    if (!sentinel) return;
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0]?.isIntersecting) void onLoadMore();
      },
      { rootMargin: "300px" },
    );
    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [pageInfo, loading, failed]);

  return (
    <>
      <div className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3">
        {posts.map((post) => (
          <PostCard
            key={post.id}
            post={post}
            locale={locale}
            readMore={t("readMore")}
          />
        ))}
      </div>

      {pageInfo.hasNextPage && (
        <div ref={sentinelRef} className="mt-10 flex justify-center" aria-live="polite">
          {failed ? (
            <button
              type="button"
              onClick={() => {
                setFailed(false);
              }}
              className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium hover:border-brand hover:text-brand dark:border-gray-700"
            >
              {t("loadError")}
            </button>
          ) : loading ? (
            <span className="text-sm text-gray-500 dark:text-gray-400">
              {t("loadMore")}
            </span>
          ) : (
            <div className="h-8" />
          )}
        </div>
      )}
    </>
  );
}
