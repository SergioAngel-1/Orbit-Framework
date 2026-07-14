import { getTranslations, setRequestLocale } from "next-intl/server";
import { fetchGraphQL } from "@/lib/graphql-client";
import { LATEST_POSTS_QUERY } from "@/lib/queries";
import { getSiteConfig } from "@/lib/config";
import { parseBanners } from "@/lib/config/banners";
import { parseFaq } from "@/lib/seo/faq";
import { PostCard } from "@/components/blog/post-card";
import { FaqSection } from "@/components/seo/faq-section";
import { HeroCarousel } from "@/components/layout/hero-carousel";
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

export default async function HomePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, config] = await Promise.all([getTranslations("home"), getSiteConfig()]);
  const faqItems = parseFaq(config.geo.faq);
  const bannerSlides = config.banners.enabled
    ? parseBanners(config.banners.slides)
    : [];

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
      {bannerSlides.length > 0 && (
        <HeroCarousel
          slides={bannerSlides}
          interval={Number(config.banners.interval_ms) || 6000}
          className="mb-12"
        />
      )}

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
          <p className="mt-3 text-sm">{t("errorDescription")}</p>
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

      <FaqSection items={faqItems} title={t("faqTitle")} />
    </div>
  );
}
