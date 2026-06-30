import Image from "next/image";
import { Link } from "@/i18n/navigation";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getProductBySlug, getProductSlugs } from "@/lib/catalog/products";
import { sanitizeHtml }   from "@/lib/security/sanitize";
import { formatPrice, stripHtml, formatDate } from "@/lib/format";
import { getSiteConfig }  from "@/lib/config";
import { absoluteLocalized, alternatesFor } from "@/lib/seo/urls";
import { buildProductJsonLd, buildBreadcrumbJsonLd } from "@/lib/seo/jsonld";
import { Badge }          from "@/components/ui/badge";
import { ProductGrid }    from "@/components/products/product-grid";
import ProductActions     from "@/components/products/product-actions";
import { ReviewForm }     from "@/components/products/review-form";

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
    const description = stripHtml(product.shortDescription ?? "").slice(0, 160);
    return {
      title: product.name,
      description,
      alternates: alternatesFor(`/products/${slug}`, locale),
      openGraph: {
        title: product.name,
        description,
        type: "website",
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
  const [t, config] = await Promise.all([
    getTranslations("products"),
    getSiteConfig(),
  ]);

  let product: Awaited<ReturnType<typeof getProductBySlug>>;
  try {
    product = await getProductBySlug(slug);
  } catch {
    notFound();
  }
  if (!product) notFound();

  const descriptionHtml = product.description ? sanitizeHtml(product.description) : "";
  const outOfStock = product.stockStatus === "OUT_OF_STOCK";
  const isVariable  = product.type === "VARIABLE";

  const canonicalUrl = absoluteLocalized(config.brand.url, `/products/${slug}`, locale);
  const jsonLd = buildProductJsonLd({
    product,
    config,
    url: canonicalUrl,
    description: stripHtml(product.shortDescription ?? product.description ?? ""),
  });

  const breadcrumbJsonLd = buildBreadcrumbJsonLd([
    { name: t("title"), url: absoluteLocalized(config.brand.url, "/products", locale) },
    { name: product.name, url: canonicalUrl },
  ]);

  return (
    <div>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbJsonLd) }}
      />

      {/* Breadcrumb */}
      <nav className="mb-6 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
        <Link href="/products" className="transition-colors hover:text-brand">
          {t("title")}
        </Link>
        <span aria-hidden>/</span>
        <span className="truncate text-gray-900 dark:text-gray-200">{product.name}</span>
      </nav>

      {/* Grid principal */}
      <div className="grid grid-cols-1 gap-10 md:grid-cols-2">
        {/* Imagen */}
        <div className="relative aspect-square overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800">
          {product.image?.sourceUrl ? (
            <Image
              src={product.image.sourceUrl}
              alt={product.image.altText || product.name}
              fill
              sizes="(max-width: 768px) 100vw, 50vw"
              className="object-cover"
              priority
            />
          ) : (
            <div className="flex h-full items-center justify-center text-5xl font-bold text-gray-300">
              {product.name.charAt(0)}
            </div>
          )}
          {product.onSale && (
            <div className="absolute left-3 top-3">
              <Badge color="brand" variant="solid">{t("onSale")}</Badge>
            </div>
          )}
        </div>

        {/* Detalles + acciones */}
        <div className="flex flex-col gap-5">
          <div>
            <h1 className="product-name text-3xl font-extrabold tracking-tight">{product.name}</h1>
            {product.shortDescription && (
              <p className="product-summary mt-2 text-gray-600 dark:text-gray-400">
                {stripHtml(product.shortDescription)}
              </p>
            )}
          </div>

          {/* Precio (lo actualiza ProductActions al seleccionar variación) */}
          <div className="flex items-baseline gap-3">
            <span className="text-2xl font-bold">{formatPrice(product.price)}</span>
            {product.onSale && product.regularPrice && (
              <span className="text-lg text-gray-400 line-through">
                {formatPrice(product.regularPrice)}
              </span>
            )}
          </div>

          {outOfStock && !isVariable && (
            <Badge color="error" variant="soft">{t("outOfStock")}</Badge>
          )}

          {/* Selector de variaciones + botón carrito (client component) */}
          <ProductActions
            productId={product.databaseId}
            isVariable={isVariable}
            outOfStock={outOfStock}
            attributes={isVariable ? product.attributes : []}
            variations={isVariable ? product.variations : []}
          />
        </div>
      </div>

      {/* Descripción completa */}
      {descriptionHtml && (
        <section className="mt-12">
          <h2 className="mb-4 text-xl font-bold">{t("description")}</h2>
          <div
            className="prose prose-sm max-w-none dark:prose-invert"
            dangerouslySetInnerHTML={{ __html: descriptionHtml }}
          />
        </section>
      )}

      {/* Reseñas */}
      <section className="mt-14">
        <h2 className="mb-6 text-xl font-bold">{t("reviews")}</h2>
        {product.reviews.items.length > 0 ? (
          <ul className="divide-y divide-gray-100 dark:divide-gray-800">
            {product.reviews.items.map((rev) => (
              <li key={rev.id} className="py-4">
                <div className="flex items-center gap-2 text-sm">
                  <span className="font-medium">{rev.authorName}</span>
                  <span className="text-yellow-500">{rev.rating > 0 ? "★".repeat(rev.rating) : ""}</span>
                  <span className="text-gray-400">{formatDate(rev.date, locale)}</span>
                </div>
                {rev.content && <p className="mt-1 text-gray-600 dark:text-gray-400">{rev.content}</p>}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-gray-500">{t("noReviews")}</p>
        )}
        <div className="mt-6">
          <ReviewForm productId={product.databaseId} />
        </div>
      </section>

      {/* Productos relacionados */}
      {product.related.length > 0 && (
        <section className="mt-14">
          <h2 className="mb-6 text-xl font-bold">{t("related")}</h2>
          <ProductGrid products={product.related} />
        </section>
      )}
    </div>
  );
}
