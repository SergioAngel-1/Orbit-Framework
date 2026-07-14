import Image from "next/image";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { Link } from "@/i18n/navigation";
import { getPostBySlug, getPostSlugs } from "@/lib/blog/posts";
import { getSiteConfig } from "@/lib/config";
import { sanitizeHtml } from "@/lib/security/sanitize";
import { stripHtml, formatDate } from "@/lib/format";
import { absoluteLocalized, alternatesFor } from "@/lib/seo/urls";
import { buildBreadcrumbJsonLd } from "@/lib/seo/jsonld";

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
  const t = await getTranslations({ locale, namespace: "blog" });
  try {
    const post = await getPostBySlug(slug);
    if (!post) return { title: t("notFound") };
    const description = stripHtml(post.excerpt).slice(0, 160);
    const image = post.featuredImage?.node;
    return {
      title: post.title,
      description,
      alternates: alternatesFor(`/blog/${slug}`, locale),
      openGraph: {
        title: post.title,
        description,
        type: "article",
        publishedTime: post.date,
        modifiedTime: post.modified ?? post.date,
        images: image ? [{ url: image.sourceUrl }] : [],
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
  const [t, config] = await Promise.all([getTranslations("blog"), getSiteConfig()]);

  let post: Awaited<ReturnType<typeof getPostBySlug>>;
  try {
    post = await getPostBySlug(slug);
  } catch {
    notFound();
  }
  if (!post) notFound();

  const contentHtml = post.content ? sanitizeHtml(post.content) : "";
  const image = post.featuredImage?.node;
  const orgBase = (config.brand.url || "").replace(/\/$/, "");
  const url = absoluteLocalized(config.brand.url, `/blog/${slug}`, locale);
  const authorNode = post.author?.node;
  const authorName = authorNode?.name ?? config.brand.name;

  // Person enriquecido con los datos del perfil de WordPress (bio, web).
  const author: Record<string, unknown> = { "@type": "Person", name: authorName };
  if (authorNode?.url) author.url = authorNode.url;
  if (authorNode?.description) author.description = authorNode.description;

  const articleJsonLd = {
    "@context": "https://schema.org",
    "@type": "BlogPosting",
    headline: post.title,
    image: image ? [image.sourceUrl] : [],
    datePublished: post.date,
    dateModified: post.modified ?? post.date,
    author,
    publisher: orgBase
      ? { "@id": `${orgBase}/#organization` }
      : { "@type": "Organization", name: config.brand.name },
    mainEntityOfPage: url,
    description: stripHtml(post.excerpt),
    // speakable: bloques óptimos para asistentes de voz/IA.
    speakable: {
      "@type": "SpeakableSpecification",
      cssSelector: [".post-headline", ".post-body"],
    },
  };

  const breadcrumbJsonLd = buildBreadcrumbJsonLd([
    { name: t("title"), url: absoluteLocalized(config.brand.url, "/blog", locale) },
    { name: post.title, url },
  ]);

  return (
    <article>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(articleJsonLd) }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbJsonLd) }}
      />

      <nav className="mb-6 text-sm text-gray-500 dark:text-gray-400">
        <Link href="/blog" className="transition-colors hover:text-brand">
          {t("backToBlog")}
        </Link>
      </nav>

      <header className="mb-8">
        <h1 className="post-headline text-3xl font-extrabold tracking-tight sm:text-4xl">
          {post.title}
        </h1>
        <div className="mt-3 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
          <time dateTime={post.date}>{formatDate(post.date, locale)}</time>
          {authorName && (
            <>
              <span aria-hidden>·</span>
              <span>{authorName}</span>
            </>
          )}
        </div>
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

      {contentHtml && (
        <div
          className="post-body prose prose-lg max-w-none dark:prose-invert"
          dangerouslySetInnerHTML={{ __html: contentHtml }}
        />
      )}
    </article>
  );
}
