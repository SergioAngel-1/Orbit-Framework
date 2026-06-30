import type { Metadata } from "next";
import { Link } from "@/i18n/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getPosts } from "@/lib/blog/posts";
import { alternatesFor } from "@/lib/seo/urls";
import { PostCard } from "@/components/blog/post-card";
import type { WPPost } from "@/types/wordpress";

export const revalidate = 60;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "blog" });
  return {
    title: t("title"),
    description: t("description"),
    alternates: alternatesFor("/blog", locale),
  };
}

export default async function BlogPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ after?: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const { after } = await searchParams;
  const t = await getTranslations("blog");

  let posts: WPPost[] = [];
  let pageInfo = { hasNextPage: false, endCursor: null as string | null };
  let errorMessage: string | null = null;

  try {
    const result = await getPosts({ after, first: 9 });
    posts = result.posts;
    pageInfo = result.pageInfo;
  } catch (e) {
    errorMessage = e instanceof Error ? e.message : t("error");
  }

  return (
    <div>
      <h1 className="mb-2 text-3xl font-extrabold tracking-tight">{t("title")}</h1>
      <p className="mb-8 max-w-2xl text-gray-600 dark:text-gray-300">{t("description")}</p>

      {errorMessage ? (
        <div className="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          <p className="font-semibold">{t("error")}</p>
          <p className="mt-1 text-sm opacity-80">{errorMessage}</p>
          <p className="mt-2 text-sm">{t("errorHint")}</p>
        </div>
      ) : posts.length === 0 ? (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-6 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
          {t("empty")}
        </div>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3">
            {posts.map((post) => (
              <PostCard key={post.id} post={post} locale={locale} readMore={t("readMore")} />
            ))}
          </div>

          {pageInfo.hasNextPage && pageInfo.endCursor && (
            <div className="mt-10 text-center">
              <Link
                href={`/blog?after=${encodeURIComponent(pageInfo.endCursor)}`}
                className="inline-flex items-center rounded-lg bg-brand px-5 py-2.5 font-semibold text-white hover:bg-brand-dark"
              >
                {t("loadMore")}
              </Link>
            </div>
          )}
        </>
      )}
    </div>
  );
}
