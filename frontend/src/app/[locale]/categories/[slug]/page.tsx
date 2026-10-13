import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getCategory } from "@/lib/catalog/products";
import { getSiteConfig } from "@/lib/config";
import { absoluteLocalized, alternatesFor } from "@/lib/seo/urls";
import { buildBreadcrumbJsonLd } from "@/lib/seo/jsonld";
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
  const t = await getTranslations({ locale, namespace: "products" });
  try {
    const result = await getCategory(slug);
    if (!result) return { title: t("notFound") };
    return {
      title: result.category.name,
      description: stripHtml(result.category.description ?? "").slice(0, 160),
      alternates: alternatesFor(`/categories/${slug}`, locale),
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

  const [t, config] = await Promise.all([getTranslations("products"), getSiteConfig()]);

  let result: Awaited<ReturnType<typeof getCategory>>;
  try {
    result = await getCategory(slug);
  } catch {
    notFound();
  }
  if (!result) notFound();

  const breadcrumbJsonLd = buildBreadcrumbJsonLd([
    { name: t("title"), url: absoluteLocalized(config.brand.url, "/products", locale) },
    {
      name: result.category.name,
      url: absoluteLocalized(config.brand.url, `/categories/${slug}`, locale),
    },
  ]);

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
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbJsonLd) }}
      />
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
