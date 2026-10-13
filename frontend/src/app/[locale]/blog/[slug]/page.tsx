import Image from "next/image";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { Link } from "@/i18n/navigation";
import { getPostBySlug, getPostSlugs, wpDateIso } from "@/lib/blog/posts";
import { buildPostView } from "@/lib/blog/post-view";
import { showUpdatedDate } from "@/lib/blog/updated-date";
import { isLandingCategory } from "@/lib/blog/category-format";
import { PostBody } from "@/components/blog/post-body";
import { PostToc } from "@/components/blog/post-toc";
import { TagLink } from "@/components/blog/tag-link";
import { AuthorLink } from "@/components/blog/author-link";
import { CategoryLink } from "@/components/blog/category-link";
import { ProductGrid } from "@/components/products/product-grid";
import { getSiteConfig } from "@/lib/config";
import { stripHtml, formatDate } from "@/lib/format";
import { absoluteLocalized, alternatesFor } from "@/lib/seo/urls";
import { buildArticleJsonLd, buildBreadcrumbJsonLd } from "@/lib/seo/jsonld";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { siteOpenGraph, siteTwitter } from "@/lib/seo/opengraph";
import { siteTitle } from "@/lib/seo/title";
import { metaDescription } from "@/lib/seo/meta-description";

export const revalidate = 300;

export async function generateStaticParams() {
  const slugs = await getPostSlugs();
  return slugs.map(({ slug }) => ({ slug }));
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "blog" }),
    getSiteConfig(),
  ]);
  try {
    const post = await getPostBySlug(slug);
    if (!post) return { title: t("notFound") };
    // La caja SEO del editor manda sobre el título y el extracto.
    const description = metaDescription(post.seoDescription || post.excerpt);
    const image = post.featuredImage?.node;
    const canonical = alternatesFor(`/blog/${slug}`, locale);
    const images = image
      ? [{ url: image.sourceUrl, alt: image.altText || post.title }]
      : undefined;
    return {
      title: siteTitle(config, post.seoTitle || post.title),
      ...(description ? { description } : {}),
      alternates: canonical,
      openGraph: {
        ...siteOpenGraph(config, locale, canonical.canonical),
        type: "article",
        publishedTime: wpDateIso(post.dateGmt, post.date),
        modifiedTime: wpDateIso(post.modifiedGmt, post.modified ?? post.date),
        ...(images ? { images } : {}),
      },
      twitter: {
        ...siteTwitter(config, locale),
        ...(images ? { images: images.map((i) => i.url) } : {}),
      },
    };
  } catch {
    return { title: t("title") };
  }
}

export default async function PostPage({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale, slug } = await params;
  setRequestLocale(locale);
  const [t, tNav, config] = await Promise.all([
    getTranslations("blog"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);

  let post: Awaited<ReturnType<typeof getPostBySlug>>;
  try {
    post = await getPostBySlug(slug, locale);
  } catch {
    notFound();
  }
  if (!post) notFound();

  const image = post.featuredImage?.node;
  const url = absoluteLocalized(config.brand.url, `/blog/${slug}`, locale);
  const authorNode = post.author?.node;
  const authorName = authorNode?.name ?? config.brand.name;
  const tags = post.tags?.nodes ?? [];
  const category = (post.categories?.nodes ?? []).find((c) =>
    isLandingCategory(c.slug),
  );

  // Cuerpo: productos enlazados, recomendaciones y TOC (ver `buildPostView`).
  const view = await buildPostView(post.content ?? "", {
    locale,
    slug,
    siteOrigins: [config.brand.url].filter(Boolean),
  });
  const updated = showUpdatedDate(post.date, post.modified);

  const articleJsonLd = buildArticleJsonLd({
    type: "BlogPosting",
    headline: post.title,
    description: stripHtml(post.excerpt),
    config,
    image: image?.sourceUrl,
    url,
    datePublished: wpDateIso(post.dateGmt, post.date),
    dateModified: wpDateIso(post.modifiedGmt, post.modified ?? post.date),
    // Person enriquecido con los datos del perfil de WordPress (bio, web).
    author: {
      name: authorName,
      url: authorNode?.url || undefined,
      description: authorNode?.description || undefined,
    },
    inLanguage: locale,
    // Bloques óptimos para asistentes de voz/IA.
    speakable: [".post-headline", ".post-body"],
  });

  const breadcrumbJsonLd = buildBreadcrumbJsonLd([
    { name: tNav("home"), url: absoluteLocalized(config.brand.url, "/", locale) },
    { name: t("title"), url: absoluteLocalized(config.brand.url, "/blog", locale) },
    { name: post.title, url },
  ]);

  return (
    <article>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(articleJsonLd) }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbJsonLd) }}
      />

      <nav className="mb-6 text-sm text-gray-500 dark:text-gray-400">
        <Link href="/blog" className="transition-colors hover:text-brand">
          {t("backToBlog")}
        </Link>
      </nav>

      <header className="mb-8">
        {category && (
          <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
            <CategoryLink category={category} />
          </p>
        )}
        <h1 className="post-headline text-3xl font-extrabold tracking-tight sm:text-4xl">
          {post.title}
        </h1>
        <div className="mt-3 flex flex-wrap items-center gap-x-2 text-sm text-gray-500 dark:text-gray-400">
          <time dateTime={wpDateIso(post.dateGmt, post.date)}>
            {formatDate(post.date, locale)}
          </time>
          {authorName && (
            <>
              <span aria-hidden>·</span>
              <AuthorLink author={{ name: authorName, slug: authorNode?.slug }} />
            </>
          )}
          {updated && post.modified && (
            <>
              <span aria-hidden>·</span>
              <time dateTime={wpDateIso(post.modifiedGmt, post.modified)}>
                {t("updated", { date: formatDate(post.modified, locale) })}
              </time>
            </>
          )}
        </div>
        {tags.length > 0 && (
          <ul className="mt-4 flex flex-wrap gap-1.5">
            {tags.map((tag) => (
              <li key={tag.slug} className="min-w-0">
                <TagLink tag={tag} size="md" />
              </li>
            ))}
          </ul>
        )}
      </header>

      {image?.sourceUrl && (
        <div className="relative mb-8 aspect-[16/9] w-full overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800">
          <Image
            src={image.sourceUrl}
            alt={image.altText || post.title}
            fill
            sizes="(max-width: 768px) 100vw, 768px"
            className="object-cover"
            priority
          />
        </div>
      )}

      <PostToc headings={view.headings} title={t("toc")} />

      <PostBody
        segments={view.segments}
        recs={view.recs}
        locale={locale}
        readMore={t("readMore")}
        recommendedLabel={t("recommendedPost")}
        wishlistEnabled={config.ecommerce.wishlist_enabled}
      />

      {view.mentioned.items.length > 0 && (
        <section className="mt-12">
          <h2 className="mb-4 text-xl font-bold tracking-tight">
            {view.mentioned.hasRelated
              ? t("mentionedAndRelated")
              : t("mentionedProducts")}
          </h2>
          <ProductGrid products={view.mentioned.items} />
        </section>
      )}
    </article>
  );
}
