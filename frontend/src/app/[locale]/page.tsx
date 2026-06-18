import Image from "next/image";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { fetchGraphQL } from "@/lib/graphql-client";
import { LATEST_POSTS_QUERY } from "@/lib/queries";
import { formatDate, stripHtml } from "@/lib/format";
import type { PostsQueryResponse, WPPost } from "@/types/wordpress";

export const revalidate = 60;

async function getLatestPosts(): Promise<WPPost[]> {
  const data = await fetchGraphQL<PostsQueryResponse>(LATEST_POSTS_QUERY, {
    variables: { first: 5 },
    revalidate: 60,
    tags: ["posts"],
  });
  return data.posts.nodes;
}

function PostCard({
  post,
  locale,
  readMore,
}: {
  post: WPPost;
  locale: string;
  readMore: string;
}) {
  const image = post.featuredImage?.node;

  return (
    <article className="group flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
      {image?.sourceUrl ? (
        <div className="relative aspect-[16/9] w-full overflow-hidden bg-gray-100 dark:bg-gray-800">
          <Image
            src={image.sourceUrl}
            alt={image.altText || post.title}
            fill
            sizes="(max-width: 768px) 100vw, 50vw"
            className="object-cover transition-transform duration-300 group-hover:scale-105"
          />
        </div>
      ) : (
        <div className="flex aspect-[16/9] w-full items-center justify-center bg-gradient-to-br from-brand-light to-brand-dark text-2xl font-bold text-white">
          {post.title.charAt(0).toUpperCase()}
        </div>
      )}

      <div className="flex flex-1 flex-col p-6">
        <div className="mb-2 flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
          <time dateTime={post.date}>{formatDate(post.date, locale)}</time>
          {post.author?.node?.name && (
            <>
              <span aria-hidden>·</span>
              <span>{post.author.node.name}</span>
            </>
          )}
        </div>

        <h2 className="mb-2 text-xl font-semibold leading-snug tracking-tight transition-colors group-hover:text-brand">
          {post.title}
        </h2>

        {post.excerpt && (
          <p className="line-clamp-3 flex-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
            {stripHtml(post.excerpt)}
          </p>
        )}

        <span className="mt-4 inline-flex items-center text-sm font-medium text-brand">
          {readMore}
        </span>
      </div>
    </article>
  );
}

export default async function HomePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const t = await getTranslations("home");

  let posts: WPPost[] = [];
  let errorMessage: string | null = null;

  try {
    posts = await getLatestPosts();
  } catch (error) {
    errorMessage =
      error instanceof Error ? error.message : "Error desconocido al cargar los posts.";
  }

  return (
    <div>
      <section className="mb-12">
        <h1 className="text-4xl font-extrabold tracking-tight sm:text-5xl">
          {t("title")}
        </h1>
        <p className="mt-3 max-w-2xl text-lg text-gray-600 dark:text-gray-300">
          {t("description")}
        </p>
      </section>

      {errorMessage ? (
        <div className="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          <p className="font-semibold">{t("errorTitle")}</p>
          <p className="mt-1 text-sm opacity-80">{errorMessage}</p>
          <p className="mt-3 text-sm">
            {t("errorDescription")}
          </p>
        </div>
      ) : posts.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-6 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
          {t("empty")}
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-8 sm:grid-cols-2">
          {posts.map((post) => (
            <PostCard
              key={post.id}
              post={post}
              locale={locale}
              readMore={t("readMore")}
            />
          ))}
        </div>
      )}
    </div>
  );
}
