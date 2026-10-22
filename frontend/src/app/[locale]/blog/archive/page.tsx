import type { Metadata } from "next";
import { Link } from "@/i18n/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getPostsIndex, type PostIndexEntry } from "@/lib/blog/posts";
import { groupPostsByYear } from "@/lib/blog/archive-format";
import { blogPostHref } from "@/lib/blog/post-href";
import { getSiteConfig } from "@/lib/config";
import { formatDate } from "@/lib/format";
import { pageMetadata } from "@/lib/seo/page-metadata";
import { buildPageBreadcrumbJsonLd } from "@/lib/seo/breadcrumbs";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";

export const revalidate = 300;

// ============================================================================
//  /blog/archive — la lista de TODAS las entradas, por año, en HTML estático.
//  El scroll infinito de /blog no lo recorre un rastreador; esta página sí.
// ============================================================================

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "blog" }),
    getSiteConfig(),
  ]);
  return pageMetadata({
    config,
    locale,
    path: "/blog/archive",
    title: t("archiveTitle"),
    description: t("archiveDescription"),
  });
}

export default async function BlogArchivePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, tNav, config] = await Promise.all([
    getTranslations("blog"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);

  // Un fallo del CMS NO se traga (ver `getPostsIndex`): se avisa, en vez de
  // servir un archivo vacío que ISR cachearía como si fuera el bueno.
  let entries: PostIndexEntry[] = [];
  let failed = false;
  try {
    entries = await getPostsIndex(locale);
  } catch {
    failed = true;
  }
  const years = groupPostsByYear(entries);

  const breadcrumbJsonLd = buildPageBreadcrumbJsonLd(config, locale, tNav("home"), [
    { name: t("title"), href: "/blog" },
    { name: t("archiveTitle"), href: "/blog/archive" },
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
      <h1 className="mb-2 text-3xl font-extrabold tracking-tight">
        {t("archiveTitle")}
      </h1>
      <p className="mb-8 max-w-2xl text-gray-600 dark:text-gray-300">
        {failed ? t("archiveError") : t("archiveDescription")}
      </p>

      {!failed && years.length === 0 && (
        <p className="text-gray-600 dark:text-gray-300">{t("archiveEmpty")}</p>
      )}

      <div className="space-y-8">
        {years.map((group) => (
          <section key={group.year || "sin-fecha"}>
            {group.year && (
              <h2 className="mb-3 text-xl font-bold tracking-tight">{group.year}</h2>
            )}
            <ul className="divide-y divide-gray-200 dark:divide-gray-800">
              {group.posts.map((post) => (
                <li
                  key={post.slug}
                  className="flex flex-wrap items-baseline justify-between gap-x-4 py-2"
                >
                  <Link
                    href={blogPostHref(post)}
                    className="font-medium transition-colors hover:text-brand dark:hover:text-accent"
                  >
                    {post.title}
                  </Link>
                  {post.date && (
                    <time
                      dateTime={post.date}
                      className="text-xs text-gray-500 dark:text-gray-400"
                    >
                      {formatDate(post.date, locale)}
                    </time>
                  )}
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </div>
  );
}
