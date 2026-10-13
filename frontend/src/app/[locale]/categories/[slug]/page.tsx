import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getCategory } from "@/lib/catalog/products";
import { getSiteConfig } from "@/lib/config";
import { absoluteLocalized, alternatesFor } from "@/lib/seo/urls";
import {
  COLLECTION_ITEM_LIST_LIMIT,
  buildBreadcrumbJsonLd,
  buildCollectionPageJsonLd,
} from "@/lib/seo/jsonld";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { siteOpenGraph, siteTwitter } from "@/lib/seo/opengraph";
import { siteTitle } from "@/lib/seo/title";
import { metaDescription } from "@/lib/seo/meta-description";
import { ProductGrid } from "@/components/products/product-grid";
import { stripHtml } from "@/lib/format";
import { CouponNotices } from "@/components/coupons/coupon-notice";
import { isModuleEnabled } from "@/lib/config/modules";
import { getCategoryCoupons } from "@/lib/coupons/public-coupons";

export const revalidate = 300;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "products" }),
    getSiteConfig(),
  ]);
  try {
    const result = await getCategory(slug);
    if (!result) return { title: t("notFound") };
    const canonical = alternatesFor(`/categories/${slug}`, locale);
    const description = metaDescription(result.category.description);
    return {
      title: siteTitle(config, result.category.name),
      ...(description ? { description } : {}),
      alternates: canonical,
      openGraph: siteOpenGraph(config, locale, canonical.canonical),
      twitter: siteTwitter(config, locale),
    };
  } catch {
    return { title: t("title") };
  }
}

export default async function CategoryPage({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale, slug } = await params;
  setRequestLocale(locale);

  const [t, tNav, config] = await Promise.all([
    getTranslations("products"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);

  let result: Awaited<ReturnType<typeof getCategory>>;
  try {
    result = await getCategory(slug);
  } catch {
    notFound();
  }
  if (!result) notFound();

  const categoryUrl = absoluteLocalized(
    config.brand.url,
    `/categories/${slug}`,
    locale,
  );
  const breadcrumbJsonLd = buildBreadcrumbJsonLd([
    { name: tNav("home"), url: absoluteLocalized(config.brand.url, "/", locale) },
    { name: t("title"), url: absoluteLocalized(config.brand.url, "/products", locale) },
    { name: result.category.name, url: categoryUrl },
  ]);
  const collectionJsonLd =
    result.products.length > 0
      ? buildCollectionPageJsonLd({
          name: result.category.name,
          description: metaDescription(result.category.description) || undefined,
          url: categoryUrl,
          items: result.products
            .slice(0, COLLECTION_ITEM_LIST_LIMIT)
            .map((product) => ({
              name: product.name,
              url: absoluteLocalized(
                config.brand.url,
                `/products/${product.slug}`,
                locale,
              ),
              image: product.image?.sourceUrl ?? null,
            })),
        })
      : null;

  // Cupones públicos que se anuncian en esta categoría (módulo `coupon-rules` y
  // `coupons_enabled`). Página ISR: `CouponGate` filtra en el navegador.
  const coupons =
    config.ecommerce.coupons_enabled && isModuleEnabled(config, "coupon-rules")
      ? await getCategoryCoupons(slug)
      : [];

  return (
    <div>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbJsonLd) }}
      />
      {collectionJsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd(collectionJsonLd) }}
        />
      )}
      <h1 className="mb-2 text-3xl font-extrabold tracking-tight">
        {result.category.name}
      </h1>
      {result.category.description && (
        <p className="mb-8 max-w-2xl text-gray-600 dark:text-gray-300">
          {stripHtml(result.category.description)}
        </p>
      )}
      {coupons.length > 0 && (
        <CouponNotices
          coupons={coupons}
          context="category"
          locale={locale}
          currency={config.ecommerce.currency}
          decimals={config.ecommerce.price_decimals}
          className="mb-8 flex max-w-2xl flex-col gap-2"
        />
      )}
      <ProductGrid products={result.products} />
    </div>
  );
}
