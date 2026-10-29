import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Link, permanentRedirect } from "@/i18n/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getCategory, getCategoryStaticParams } from "@/lib/catalog/products";
import { resolveEntityRoute } from "@/lib/i18n/entity-route";
import { productHref } from "@/lib/catalog/product-href";
import { getSiteConfig } from "@/lib/config";
import { absoluteLocalized, alternatesFor, entityPaths } from "@/lib/seo/urls";
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
import { logReadFailure } from "@/lib/observability/read-failure";

export const revalidate = 300;

// Las categorías son pocas: se hornean todas, cada una en los idiomas en que existe.
// Sin esto la ruta se renderiza en cada petición, sin ISR y con los 404/308 en 200.
export async function generateStaticParams() {
  return getCategoryStaticParams();
}

/**
 * La categoría (con sus productos) a partir del tramo de URL, en cualquier
 * idioma. `urlSlug` se copia al nivel superior: es lo que `resolveEntityRoute`
 * usa para calcular la URL canónica.
 */
function loadCategoryRoute(slug: string, locale: string) {
  return resolveEntityRoute(
    "product_cat",
    "/categories",
    slug,
    locale,
    async (native) => {
      const found = await getCategory(native, locale);
      return found ? { ...found, urlSlug: found.category.urlSlug } : null;
    },
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
    const route = await loadCategoryRoute(slug, locale);
    if (!route) return { title: t("notFound") };
    const result = route.entity;
    const canonical = alternatesFor(`/categories/${route.nativeSlug}`, locale, {
      locales: result.category.locales,
      paths: entityPaths("/categories", result.category.urlSlugs),
    });
    // La caja SEO del editor manda sobre el nombre y la descripción.
    const description = metaDescription(
      result.category.seoDescription || result.category.description,
    );
    return {
      title: siteTitle(config, result.category.seoTitle || result.category.name),
      ...(description ? { description } : {}),
      alternates: canonical,
      openGraph: siteOpenGraph(config, locale, canonical.canonical),
      twitter: siteTwitter(config, locale),
    };
  } catch (error) {
    // Sin metadatos de la ficha se sirve el título genérico, pero queda en el log.
    logReadFailure("categories.metadata_failed", error, {
      level: "warn",
      context: { slug, locale },
      message: "No se pudieron leer los metadatos de la categoría",
    });
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

  // Un fallo de WordPress NO es «no existe»: se propaga (500, que ISR no cachea y
  // `onRequestError` registra) en vez de hornear un 404 por una caída transitoria.
  const route = await loadCategoryRoute(slug, locale);
  if (!route) notFound();
  // El tramo pedido puede ser el nativo o el de otro idioma: 308 al del idioma.
  if (!route.isCanonical) permanentRedirect({ href: route.canonicalHref, locale });
  const result = route.entity;

  const categoryUrl = absoluteLocalized(config.brand.url, route.canonicalHref, locale);
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
              url: absoluteLocalized(config.brand.url, productHref(product), locale),
              image: product.image?.sourceUrl ?? null,
            })),
        })
      : null;

  // Cupones públicos que se anuncian en esta categoría (módulo `coupon-rules` y
  // `coupons_enabled`). Página ISR: `CouponGate` filtra en el navegador.
  const coupons =
    config.ecommerce.coupons_enabled && isModuleEnabled(config, "coupon-rules")
      ? await getCategoryCoupons(route.nativeSlug)
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
      {/* La categoría pinta una sola tanda: el índice A-Z enlaza el resto. */}
      {result.products.length > 0 && (
        <Link
          href="/products/archive"
          className="mt-10 inline-block text-sm font-semibold text-brand hover:underline dark:text-accent"
        >
          {t("viewArchive")}
        </Link>
      )}
    </div>
  );
}
