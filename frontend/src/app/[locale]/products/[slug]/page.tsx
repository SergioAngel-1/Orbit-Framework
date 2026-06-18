import Image from "next/image";
import { Link } from "@/i18n/navigation";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getProductBySlug, getProductSlugs } from "@/lib/catalog/products";
import { AddToCartButton } from "@/components/cart/add-to-cart-button";
import { sanitizeHtml } from "@/lib/security/sanitize";
import { formatPrice, stripHtml } from "@/lib/format";

export const revalidate = 300;

export async function generateStaticParams() {
  const slugs = await getProductSlugs();
  return slugs.map((slug) => ({ slug }));
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  const t = await getTranslations({ locale, namespace: "products" });
  try {
    const product = await getProductBySlug(slug);
    if (!product) return { title: t("notFound") };
    const description = stripHtml(product.shortDescription).slice(0, 160);
    return {
      title: product.name,
      description,
      openGraph: {
        title: product.name,
        description,
        images: product.image ? [{ url: product.image.sourceUrl }] : [],
      },
    };
  } catch {
    return { title: t("title") };
  }
}

export default async function ProductPage({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale, slug } = await params;
  setRequestLocale(locale);
  const t = await getTranslations("products");

  let product: Awaited<ReturnType<typeof getProductBySlug>>;
  try {
    product = await getProductBySlug(slug);
  } catch {
    notFound();
  }
  if (!product) notFound();

  const descriptionHtml = product.description ? sanitizeHtml(product.description) : "";
  const outOfStock = product.stockStatus === "OUT_OF_STOCK";

  const jsonLd = {
    "@context": "https://schema.org/",
    "@type": "Product",
    name: product.name,
    image: product.image ? [product.image.sourceUrl] : [],
    description: stripHtml(product.shortDescription || product.description),
    offers: {
      "@type": "Offer",
      price: formatPrice(product.price).replace(/[^\d.,]/g, ""),
      availability: outOfStock
        ? "https://schema.org/OutOfStock"
        : "https://schema.org/InStock",
    },
  };

  return (
    <div>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />

      <nav className="mb-6 text-sm text-gray-500">
        <Link href="/products" className="hover:text-brand">
          {t("backToStore")}
        </Link>
      </nav>

      <div className="grid grid-cols-1 gap-10 md:grid-cols-2">
        <div className="relative aspect-square overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800">
          {product.image?.sourceUrl && (
            <Image
              src={product.image.sourceUrl}
              alt={product.image.altText || product.name}
              fill
              sizes="(max-width: 768px) 100vw, 50vw"
              className="object-cover"
              priority
            />
          )}
        </div>

        <div>
          <h1 className="text-3xl font-extrabold tracking-tight">{product.name}</h1>

          <div className="mt-4 flex items-baseline gap-3">
            <span className="text-2xl font-bold">{formatPrice(product.price)}</span>
            {product.onSale && product.regularPrice && (
              <span className="text-lg text-gray-400 line-through">
                {formatPrice(product.regularPrice)}
              </span>
            )}
          </div>

          <div className="mt-6">
            {outOfStock ? (
              <span className="font-medium text-gray-400">{t("outOfStock")}</span>
            ) : (
              <AddToCartButton productId={product.databaseId} />
            )}
          </div>

          {descriptionHtml && (
            <div
              className="prose prose-sm mt-8 max-w-none dark:prose-invert"
              dangerouslySetInnerHTML={{ __html: descriptionHtml }}
            />
          )}
        </div>
      </div>
    </div>
  );
}
