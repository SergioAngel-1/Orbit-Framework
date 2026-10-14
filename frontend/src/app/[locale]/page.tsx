import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { alternatesFor } from "@/lib/seo/urls";
import { siteOpenGraph, siteTwitter } from "@/lib/seo/opengraph";
import { viewSocialImages } from "@/lib/seo/og-static";
import { fetchGraphQL } from "@/lib/graphql-client";
import { LATEST_POSTS_QUERY } from "@/lib/queries";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { getBanner } from "@/lib/banners";
import {
  faqItems as localizedFaqItems,
  paymentsFaqItems as localizedPaymentsFaqItems,
} from "@/lib/config/faq";
import { getBestSellingProducts } from "@/lib/catalog/best-sellers";
import { PostCard } from "@/components/blog/post-card";
import { FaqSection } from "@/components/seo/faq-section";
import { HeroCarousel } from "@/components/layout/hero-carousel";
import { ProductGrid } from "@/components/products/product-grid";
import type { PostsQueryResponse, WPPost } from "@/types/wordpress";

export const revalidate = 60;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const config = await getSiteConfig();
  // El título y la descripción son los del layout (marca y descripción del
  // CMS): aquí solo lo que es propio de la home, su canónica y su tarjeta.
  const canonical = alternatesFor("/", locale);
  const cards = viewSocialImages("home", locale, config.brand.name);
  return {
    alternates: canonical,
    openGraph: {
      ...siteOpenGraph(config, locale, canonical.canonical),
      ...cards.openGraph,
    },
    twitter: { ...siteTwitter(config, locale), ...cards.twitter },
  };
}

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
  const [t, config, bestSellers] = await Promise.all([
    getTranslations("home"),
    getSiteConfig(),
    getBestSellingProducts(4),
  ]);
  const faqItems = localizedFaqItems(config, locale);
  const paymentsFaqItems = localizedPaymentsFaqItems(config, locale);
  const heroBanner = config.banners.enabled
    ? await getBanner("carousel", "home", locale, {
        enabled: isModuleEnabled(config, "banners"),
      })
    : { slides: [], intervalMs: 6000 };
  // El carrusel del starter es de fotos: un slide sin imagen (solo texto o con un visual
  // decorativo) lo pinta la plantilla que la instancia añada, no este.
  const bannerSlides = heroBanner.slides.filter((slide) => slide.image !== "");

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
          interval={heroBanner.intervalMs}
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

      {bestSellers.length > 0 && (
        <section className="mb-12" aria-labelledby="best-sellers-title">
          <h2 id="best-sellers-title" className="mb-6 text-2xl font-bold">
            {t("bestSellersTitle")}
          </h2>
          <ProductGrid products={bestSellers} />
        </section>
      )}

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

      <FaqSection
        items={faqItems}
        paymentItems={paymentsFaqItems}
        title={t("faqTitle")}
        paymentsTitle={t("faqPaymentsTitle")}
      />
    </div>
  );
}
