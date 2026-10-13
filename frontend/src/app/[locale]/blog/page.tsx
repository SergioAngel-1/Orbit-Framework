import type { Metadata } from "next";
import Image from "next/image";
import { Link, permanentRedirect } from "@/i18n/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import {
  getAuthorBySlug,
  getFeaturedPostOverride,
  getPosts,
  getTagBySlug,
  getTopTags,
} from "@/lib/blog/posts";
import { getBlogCategories, getBlogCategory } from "@/lib/blog/categories";
import {
  blogCategoryHref,
  categoryPageAvailable,
  isLandingCategory,
} from "@/lib/blog/category-format";
import { blogPostHref } from "@/lib/blog/post-href";
import { pickTopTags } from "@/lib/blog/top-tags";
import { getSiteConfig } from "@/lib/config";
import { cleanExcerpt, formatDate } from "@/lib/format";
import { pageMetadata } from "@/lib/seo/page-metadata";
import { DERIVED_LISTING_ROBOTS } from "@/lib/seo/listing";
import { buildPageBreadcrumbJsonLd } from "@/lib/seo/breadcrumbs";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { siteTitle } from "@/lib/seo/title";
import { InfinitePostGrid } from "@/components/blog/infinite-post-grid";
import { BlogSearchBar } from "@/components/blog/blog-search-bar";
import { TagLink } from "@/components/blog/tag-link";
import { AuthorLink } from "@/components/blog/author-link";
import type { WPPost } from "@/types/wordpress";

export const revalidate = 300;

type BlogSearchParams = {
  tag?: string;
  author?: string;
  category?: string;
  search?: string;
  /** Cursor heredado de la paginación de la v1 (`?after=`). */
  after?: string;
};

export async function generateMetadata({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<BlogSearchParams>;
}): Promise<Metadata> {
  const { locale } = await params;
  const { tag, author, category, search, after } = await searchParams;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "blog" }),
    getSiteConfig(),
  ]);
  // Filtros por etiqueta/autor/categoría/búsqueda son la misma data recortada de
  // mil formas: no vale la pena indexarlos todos. La vista filtrada va
  // `noindex, follow` —`follow` mantiene el descubrimiento de las entradas— y
  // SIN `alternates` (ver `lib/seo/listing.ts`).
  if (tag || author || category || search || after) {
    return {
      title: siteTitle(config, t("title")),
      description: t("description"),
      robots: DERIVED_LISTING_ROBOTS,
    };
  }
  return pageMetadata({
    config,
    locale,
    path: "/blog",
    title: t("title"),
    description: t("description"),
    view: "blog",
  });
}

const PER_PAGE = 9;
/** Etiquetas visibles en el destacado (una fila); el resto, «+N». */
const FEATURED_TAG_LIMIT = 2;
const TOP_TAGS = 20;

export default async function BlogPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<BlogSearchParams>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const {
    tag: tagSlug,
    author: authorSlug,
    category: categorySlug,
    search: searchQuery,
  } = await searchParams;
  const isFiltered = Boolean(tagSlug || authorSlug || categorySlug || searchQuery);

  // `?category=x` a secas es la misma lista que la página de la categoría, pero
  // `noindex` y sin canónica: se consolida en la URL propia cuando esa página
  // existe (con entradas, no un cajón). Con otro filtro encima (búsqueda,
  // etiqueta, autor) es una vista distinta y se queda.
  if (
    categorySlug &&
    !tagSlug &&
    !authorSlug &&
    !searchQuery &&
    isLandingCategory(categorySlug)
  ) {
    const category = await getBlogCategory(categorySlug).catch(() => null);
    if (categoryPageAvailable(category)) {
      permanentRedirect({ href: blogCategoryHref(categorySlug), locale });
    }
  }

  const [t, tNav, config] = await Promise.all([
    getTranslations("blog"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);

  let posts: WPPost[] = [];
  let pageInfo = { hasNextPage: false, endCursor: null as string | null };
  let errorMessage: string | null = null;
  let heading: string | null = null;

  try {
    const [result, filterTag, filterAuthor, filterCategory] = await Promise.all([
      getPosts({
        first: PER_PAGE,
        tag: tagSlug,
        author: authorSlug,
        category: categorySlug,
        search: searchQuery,
        locale,
      }),
      tagSlug ? getTagBySlug(tagSlug, locale).catch(() => null) : null,
      authorSlug ? getAuthorBySlug(authorSlug).catch(() => null) : null,
      categorySlug ? getBlogCategory(categorySlug).catch(() => null) : null,
    ]);
    posts = result.posts;
    pageInfo = result.pageInfo;
    if (filterTag) heading = t("filterTag", { name: filterTag.name });
    else if (filterAuthor) heading = t("filterAuthor", { name: filterAuthor.name });
    else if (filterCategory)
      heading = t("filterCategory", { name: filterCategory.name });
    else if (searchQuery) heading = t("filterSearch", { query: searchQuery });
  } catch (e) {
    errorMessage = e instanceof Error ? e.message : t("error");
  }

  // Sin filtro, la entrada destacada (la fijada en wp-admin o, si no hay, la más
  // reciente) abre el listado y el resto va debajo, sin repetirla.
  let featured: WPPost | null = null;
  let rest = posts;
  if (!isFiltered && posts.length > 0) {
    const override = await getFeaturedPostOverride(locale);
    featured = override ?? posts[0] ?? null;
    rest = posts.filter((post) => post.id !== featured?.id);
  }

  const [categories, topTags] = await Promise.all([
    getBlogCategories().catch(() => []),
    getTopTags(TOP_TAGS, locale).catch(() => []),
  ]);

  const featuredTags = pickTopTags(featured?.tags?.nodes ?? [], FEATURED_TAG_LIMIT);
  const featuredImage = featured?.featuredImage?.node;
  const breadcrumbJsonLd = isFiltered
    ? null
    : buildPageBreadcrumbJsonLd(config, locale, tNav("home"), [
        { name: t("title"), href: "/blog" },
      ]);

  return (
    <div>
      {breadcrumbJsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbJsonLd) }}
        />
      )}

      <h1 className="mb-2 text-3xl font-extrabold tracking-tight">{t("title")}</h1>
      <p className="mb-8 max-w-2xl text-gray-600 dark:text-gray-300">
        {heading ?? t("description")}
      </p>

      {errorMessage ? (
        <div className="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          <p className="font-semibold">{t("error")}</p>
          <p className="mt-1 text-sm opacity-80">{errorMessage}</p>
          <p className="mt-2 text-sm">{t("errorHint")}</p>
        </div>
      ) : (
        <div className="flex flex-col gap-8">
          {featured && (
            <div className="group relative grid overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm md:grid-cols-2 dark:border-gray-800 dark:bg-gray-900">
              {featuredImage?.sourceUrl && (
                <div className="relative aspect-[16/9] bg-gray-100 md:aspect-auto md:min-h-[260px] dark:bg-gray-800">
                  <Image
                    src={featuredImage.sourceUrl}
                    alt={featuredImage.altText || ""}
                    fill
                    sizes="(max-width: 768px) 100vw, 50vw"
                    className="object-cover"
                    priority
                  />
                </div>
              )}
              <div className="flex flex-col gap-3 p-6">
                <span className="text-xs font-semibold uppercase tracking-wide text-brand">
                  {t("featuredBadge")}
                </span>
                <div className="flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
                  <time dateTime={featured.date}>
                    {formatDate(featured.date, locale)}
                  </time>
                  {featured.author?.node?.name && (
                    <>
                      <span aria-hidden>·</span>
                      <AuthorLink author={featured.author.node} />
                    </>
                  )}
                </div>
                <h2 className="text-2xl font-bold leading-snug tracking-tight">
                  <Link
                    href={blogPostHref(featured)}
                    className="after:absolute after:inset-0 after:content-['']"
                  >
                    {featured.title}
                  </Link>
                </h2>
                {featured.excerpt && (
                  <p className="text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    {cleanExcerpt(featured.excerpt, 200)}
                  </p>
                )}
                {featuredTags.visible.length > 0 && (
                  <ul className="flex min-w-0 flex-wrap items-center gap-1.5">
                    {featuredTags.visible.map((tag) => (
                      <li key={tag.slug} className="min-w-0">
                        <TagLink tag={tag} />
                      </li>
                    ))}
                    {featuredTags.hidden.length > 0 && (
                      <li className="shrink-0">
                        <span
                          title={featuredTags.hidden.map((tag) => tag.name).join(", ")}
                          className="relative z-10 inline-flex items-center rounded-full border border-gray-200 px-2 py-1 text-[11px] font-medium leading-none text-gray-600 dark:border-gray-800 dark:text-gray-300"
                        >
                          +{featuredTags.hidden.length}
                        </span>
                      </li>
                    )}
                  </ul>
                )}
                <span className="mt-auto text-sm font-semibold text-brand">
                  {t("readArticle")}
                </span>
              </div>
            </div>
          )}

          {/* El buscador va SIEMPRE en esta posición del árbol, con y sin
              resultados: busca mientras se escribe, y si el aviso de «sin
              resultados» lo pusiera en otra rama, React lo remontaría y se
              perdería el foco a mitad de palabra. */}
          <BlogSearchBar
            initialQuery={searchQuery ?? ""}
            tags={topTags}
            categories={categories}
          />

          {posts.length === 0 && (
            <div className="flex flex-col items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 p-6 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
              <p>{isFiltered ? t("filterEmpty") : t("empty")}</p>
              {isFiltered && (
                <Link
                  href="/blog"
                  className="text-sm font-semibold text-brand hover:underline"
                >
                  {t("clearFilter")}
                </Link>
              )}
            </div>
          )}

          {rest.length > 0 && (
            <InfinitePostGrid
              initialPosts={rest}
              initialPageInfo={pageInfo}
              locale={locale}
              filters={{
                tag: tagSlug,
                author: authorSlug,
                category: categorySlug,
                search: searchQuery,
              }}
            />
          )}

          {/* El scroll infinito no lo recorre un rastreador: este enlace, en el
              HTML inicial, lleva a la lista de TODAS las entradas. */}
          {!isFiltered && posts.length > 0 && (
            <Link
              href="/blog/archive"
              className="self-start text-sm font-semibold text-brand hover:underline"
            >
              {t("viewArchive")}
            </Link>
          )}
        </div>
      )}
    </div>
  );
}
