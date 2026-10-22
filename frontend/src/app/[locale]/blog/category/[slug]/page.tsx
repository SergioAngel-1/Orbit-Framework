import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Link } from "@/i18n/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getPosts } from "@/lib/blog/posts";
import { getBlogCategory } from "@/lib/blog/categories";
import { categoryPageAvailable } from "@/lib/blog/category-format";
import { getSiteConfig } from "@/lib/config";
import { pageMetadata } from "@/lib/seo/page-metadata";
import { buildPageBreadcrumbJsonLd } from "@/lib/seo/breadcrumbs";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { InfinitePostGrid } from "@/components/blog/infinite-post-grid";
import type { WPPost } from "@/types/wordpress";

export const revalidate = 300;

// ============================================================================
//  /blog/category/<slug> — página propia de una categoría del blog.
//
//  `categoryPageAvailable` es el ÚNICO criterio de disponibilidad: lo usan esta
//  página, el sitemap y los enlaces, para que ninguno anuncie una URL que los
//  otros dos no sirven (una categoría vacía o un cajón responde 404).
// ============================================================================

const PER_PAGE = 9;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  const [t, config, category] = await Promise.all([
    getTranslations({ locale, namespace: "blog" }),
    getSiteConfig(),
    getBlogCategory(slug, locale).catch(() => null),
  ]);
  if (!category || !categoryPageAvailable(category)) return { title: t("notFound") };
  return pageMetadata({
    config,
    locale,
    path: `/blog/category/${slug}`,
    alternates: { locales: category.locales },
    title: category.name,
    description: category.description ?? t("categoryFallback", { name: category.name }),
  });
}

export default async function BlogCategoryPage({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale, slug } = await params;
  setRequestLocale(locale);

  const category = await getBlogCategory(slug, locale).catch(() => null);
  if (!category || !categoryPageAvailable(category)) notFound();

  const [t, tNav, config] = await Promise.all([
    getTranslations("blog"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);

  let posts: WPPost[] = [];
  let pageInfo = { hasNextPage: false, endCursor: null as string | null };
  try {
    const result = await getPosts({ first: PER_PAGE, category: slug, locale });
    posts = result.posts;
    pageInfo = result.pageInfo;
  } catch {
    /* la página sigue: el encabezado y el enlace al blog no dependen de esto */
  }

  const breadcrumbJsonLd = buildPageBreadcrumbJsonLd(config, locale, tNav("home"), [
    { name: t("title"), href: "/blog" },
    { name: category.name, href: `/blog/category/${slug}` },
  ]);

  return (
    <div>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbJsonLd) }}
      />
      <nav className="mb-6 text-sm text-gray-500 dark:text-gray-400">
        <Link
          href="/blog"
          className="transition-colors hover:text-brand dark:hover:text-accent"
        >
          {t("backToBlog")}
        </Link>
      </nav>
      <h1 className="mb-2 text-3xl font-extrabold tracking-tight">{category.name}</h1>
      <p className="mb-8 max-w-2xl text-gray-600 dark:text-gray-300">
        {category.description ?? t("categoryFallback", { name: category.name })}
      </p>

      {posts.length === 0 ? (
        <p className="text-gray-600 dark:text-gray-300">{t("filterEmpty")}</p>
      ) : (
        <InfinitePostGrid
          initialPosts={posts}
          initialPageInfo={pageInfo}
          locale={locale}
          filters={{ category: slug }}
        />
      )}
    </div>
  );
}
