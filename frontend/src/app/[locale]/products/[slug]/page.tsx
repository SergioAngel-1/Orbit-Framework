import { CmsImage } from "@/components/ui/cms-image";
import { Link, permanentRedirect } from "@/i18n/navigation";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getProductBySlug, getProductUrls } from "@/lib/catalog/products";
import { resolveEntityRoute } from "@/lib/i18n/entity-route";
import { prerenderLongTail } from "@/lib/prerender-budget";
import { sanitizeHtml } from "@/lib/security/sanitize";
import { formatPrice, stripHtml, formatDate } from "@/lib/format";
import { getSiteConfig } from "@/lib/config";
import { absoluteLocalized, alternatesFor, entityPaths } from "@/lib/seo/urls";
import { buildProductJsonLd, buildBreadcrumbJsonLd } from "@/lib/seo/jsonld";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { siteOpenGraph, siteTwitter } from "@/lib/seo/opengraph";
import { siteTitle } from "@/lib/seo/title";
import { metaDescription } from "@/lib/seo/meta-description";
import { Badge } from "@/components/ui/badge";
import { ProductGrid } from "@/components/products/product-grid";
import ProductActions from "@/components/products/product-actions";
import { ReviewGate } from "@/components/products/review-gate";
import { CouponNotices } from "@/components/coupons/coupon-notice";
import { ViewItemTracker } from "@/components/analytics/view-item-tracker";
import { viewItemParams } from "@/lib/analytics/ecommerce";
import { isModuleEnabled } from "@/lib/config/modules";
import { getProductCoupons } from "@/lib/coupons/public-coupons";
import { getCartProductRefs } from "@/lib/catalog/coupon-refs";
import { logReadFailure } from "@/lib/observability/read-failure";
import { hasProductLanding } from "@/lib/landings/product-landing";
import { productLearnHref } from "@/lib/landings/product-learn-href";

export const revalidate = 300;

export async function generateStaticParams() {
  // La cola larga se hornea o se deja para ISR según `PRERENDER_LONG_TAIL`. Cada
  // ficha se hornea solo en los idiomas en que existe, con su tramo de URL.
  const products = await prerenderLongTail(getProductUrls);
  return products.flatMap(({ slugs, locales }) =>
    locales.map((locale) => ({ locale, slug: slugs[locale]! })),
  );
}

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
    const route = await resolveEntityRoute(
      "product",
      "/products",
      slug,
      locale,
      (native) => getProductBySlug(native, locale),
    );
    if (!route) return { title: t("notFound") };
    const product = route.entity;
    // La caja SEO del editor manda sobre el nombre y el resumen del producto.
    const description = metaDescription(
      product.seoDescription || product.shortDescription || product.description,
    );
    const canonical = alternatesFor(`/products/${route.nativeSlug}`, locale, {
      locales: product.locales,
      paths: entityPaths("/products", product.urlSlugs),
    });
    // Next REEMPLAZA `openGraph` entero: los campos de sitio (siteName, locale,
    // alternateLocale) salen de `siteOpenGraph`, y la foto del producto manda
    // sobre la tarjeta por defecto.
    const images = product.image
      ? [{ url: product.image.sourceUrl, alt: product.image.altText || product.name }]
      : undefined;
    return {
      title: siteTitle(config, product.seoTitle || product.name),
      ...(description ? { description } : {}),
      alternates: canonical,
      openGraph: {
        ...siteOpenGraph(config, locale, canonical.canonical),
        ...(images ? { images } : {}),
      },
      twitter: {
        ...siteTwitter(config, locale),
        ...(images ? { images: images.map((image) => image.url) } : {}),
      },
    };
  } catch (error) {
    // Sin metadatos de la ficha se sirve el título genérico, pero queda en el log.
    logReadFailure("products.metadata_failed", error, {
      level: "warn",
      context: { slug, locale },
      message: "No se pudieron leer los metadatos de la ficha",
    });
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
  const [t, tNav, config] = await Promise.all([
    getTranslations("products"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);

  // Un fallo de WordPress NO es «no existe»: se propaga (500, que ISR no cachea y
  // `onRequestError` registra) en vez de hornear un 404 por una caída transitoria.
  const route = await resolveEntityRoute(
    "product",
    "/products",
    slug,
    locale,
    (native) => getProductBySlug(native, locale),
  );
  if (!route) notFound();
  // El tramo pedido puede ser el nativo o el de otro idioma: 308 al del idioma.
  if (!route.isCanonical) permanentRedirect({ href: route.canonicalHref, locale });
  const product = route.entity;

  const descriptionHtml = product.description ? sanitizeHtml(product.description) : "";
  const outOfStock = product.stockStatus === "OUT_OF_STOCK";
  const isVariable = product.type === "VARIABLE";

  const canonicalUrl = absoluteLocalized(config.brand.url, route.canonicalHref, locale);
  const jsonLd = buildProductJsonLd({
    product,
    config,
    url: canonicalUrl,
    description: stripHtml(product.shortDescription ?? product.description ?? ""),
  });

  const breadcrumbJsonLd = buildBreadcrumbJsonLd([
    { name: tNav("home"), url: absoluteLocalized(config.brand.url, "/", locale) },
    { name: t("title"), url: absoluteLocalized(config.brand.url, "/products", locale) },
    { name: product.name, url: canonicalUrl },
  ]);

  // Cupones públicos que aplican a este producto (módulo `coupon-rules` y
  // `coupons_enabled`). La ficha es ISR: el aviso lo gobierna `CouponGate` en el
  // navegador (ventana de fechas y cupones ya agotados por el cliente).
  const productCoupons =
    config.ecommerce.coupons_enabled && isModuleEnabled(config, "coupon-rules")
      ? await getProductCoupons({
          slug: product.slug,
          onSale: product.onSale,
          categorySlugs:
            (await getCartProductRefs([product.databaseId])).get(product.databaseId)
              ?.categorySlugs ?? [],
        })
      : [];

  // Guía del producto (módulo `product-landing`): solo si está publicada y, en un
  // idioma secundario, traducida.
  const hasLearnPage = await hasProductLanding(route.nativeSlug, locale);

  return (
    <div>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(jsonLd) }}
      />
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbJsonLd) }}
      />

      {/* Medición: `view_item` una vez por ficha (la página es ISR). */}
      <ViewItemTracker
        params={viewItemParams(
          { databaseId: product.databaseId, name: product.name, price: product.price },
          config.ecommerce.currency,
        )}
      />

      {/* Breadcrumb */}
      <nav className="mb-6 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
        <Link href="/products" className="transition-colors hover:text-brand">
          {t("title")}
        </Link>
        <span aria-hidden>/</span>
        <span className="truncate text-gray-900 dark:text-gray-200">
          {product.name}
        </span>
      </nav>

      {/* Grid principal */}
      <div className="grid grid-cols-1 gap-10 md:grid-cols-2">
        {/* Imagen */}
        <div className="relative aspect-square overflow-hidden rounded-2xl bg-gray-100 dark:bg-gray-800">
          {product.image?.sourceUrl ? (
            <CmsImage
              src={product.image.sourceUrl}
              alt={product.image.altText || product.name}
              fill
              sizes="(max-width: 768px) 100vw, 50vw"
              priority
            />
          ) : (
            <div className="flex h-full items-center justify-center text-5xl font-bold text-gray-300">
              {product.name.charAt(0)}
            </div>
          )}
          {product.image?.isReference && (
            <p className="absolute bottom-3 left-3 rounded-md bg-surface/90 px-2 py-1 text-xs font-medium text-gray-700 dark:bg-gray-900/90 dark:text-gray-200">
              {t("referenceImage")}
            </p>
          )}
          {product.onSale && (
            <div className="absolute left-3 top-3">
              <Badge color="brand" variant="solid">
                {t("onSale")}
              </Badge>
            </div>
          )}
        </div>

        {/* Detalles + acciones */}
        <div className="flex flex-col gap-5">
          <div>
            <h1 className="product-name text-3xl font-extrabold tracking-tight">
              {product.name}
            </h1>
            {product.shortDescription && (
              <p className="product-summary mt-2 text-gray-600 dark:text-gray-400">
                {stripHtml(product.shortDescription)}
              </p>
            )}
          </div>

          {hasLearnPage && (
            <Link
              href={productLearnHref(product)}
              className="inline-flex w-fit items-center gap-1 text-sm font-semibold text-brand hover:underline"
            >
              {t("learnMore")} <span aria-hidden>→</span>
            </Link>
          )}

          {productCoupons.length > 0 && (
            <CouponNotices
              coupons={productCoupons}
              context="product"
              locale={locale}
              currency={config.ecommerce.currency}
              decimals={config.ecommerce.price_decimals}
            />
          )}

          {/* Precio (lo actualiza ProductActions al seleccionar variación) */}
          <div className="flex items-baseline gap-3">
            <span className="text-2xl font-bold">
              {formatPrice(
                product.price,
                config.ecommerce.currency,
                locale,
                config.ecommerce.price_decimals,
              )}
            </span>
            {product.onSale && product.regularPrice && (
              <span className="text-lg text-gray-400 line-through">
                {formatPrice(
                  product.regularPrice,
                  config.ecommerce.currency,
                  locale,
                  config.ecommerce.price_decimals,
                )}
              </span>
            )}
          </div>

          {outOfStock && !isVariable && (
            <Badge color="error" variant="soft">
              {t("outOfStock")}
            </Badge>
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

      {/* Reseñas — solo si la instancia las tiene habilitadas (ecommerce.reviews_enabled) */}
      {config.ecommerce.reviews_enabled && (
        <section className="mt-14">
          <h2 className="mb-6 text-xl font-bold">{t("reviews")}</h2>
          {product.reviews.items.length > 0 ? (
            <ul className="divide-y divide-gray-100 dark:divide-gray-800">
              {product.reviews.items.map((rev) => (
                <li key={rev.id} className="py-4">
                  <div className="flex items-center gap-2 text-sm">
                    <span className="font-medium">{rev.authorName}</span>
                    <span className="text-yellow-500">
                      {rev.rating > 0 ? "★".repeat(rev.rating) : ""}
                    </span>
                    <span className="text-gray-400">
                      {formatDate(rev.date, locale)}
                    </span>
                  </div>
                  {rev.content && (
                    <p className="mt-1 text-gray-600 dark:text-gray-400">
                      {rev.content}
                    </p>
                  )}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-gray-500">{t("noReviews")}</p>
          )}
          <div className="mt-6">
            <ReviewGate productId={product.databaseId} />
          </div>
        </section>
      )}

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
