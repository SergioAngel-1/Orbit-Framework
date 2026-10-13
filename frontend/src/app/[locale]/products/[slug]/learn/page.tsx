import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { permanentRedirect } from "@/i18n/navigation";
import { routing } from "@/i18n/routing";
import { LandingProductView } from "@/components/landings/product/landing-product-view";
import { getProductBySlug, getProductUrls } from "@/lib/catalog/products";
import { productHref } from "@/lib/catalog/product-href";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { stripHtml } from "@/lib/format";
import { resolveEntityRoute } from "@/lib/i18n/entity-route";
import { advicePageFaq } from "@/lib/landings/advice";
import {
  getProductLanding,
  getPublishedProductLandingLocales,
  getPublishedProductLandingSlugs,
} from "@/lib/landings/product-landing";
import { productLearnPaths } from "@/lib/landings/product-learn-href";
import { getRelatedProductLandings } from "@/lib/landings/related-product-landings";
import { logReadFailure } from "@/lib/observability/read-failure";
import { prerenderLongTail } from "@/lib/prerender-budget";
import { metaDescription } from "@/lib/seo/meta-description";
import { siteOpenGraph, siteTwitter } from "@/lib/seo/opengraph";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { siteTitle } from "@/lib/seo/title";
import { absoluteLocalized, alternatesFor } from "@/lib/seo/urls";
import {
  buildArticleJsonLd,
  buildBreadcrumbJsonLd,
  buildFaqJsonLd,
  buildProductJsonLd,
  buildVideoObjectJsonLd,
} from "@/lib/seo/jsonld";
import { extractYoutubeId } from "@/lib/youtube";

// ============================================================================
//  Landing de producto — `/products/[slug]/learn` (módulo `product-landing`).
//
//  Cuelga de la ficha, así que comparte su tramo de URL por idioma y su 308
//  hacia el canónico. Solo existe si el módulo está activo Y la landing del
//  producto está publicada: en cualquier otro caso, 404.
//
//  ISR igual que la ficha. `generateStaticParams` solo enumera las landings
//  publicadas, en los idiomas en que existen (sin traducción no se hornea ni se
//  anuncia ese idioma).
// ============================================================================

export const revalidate = 300;

export async function generateStaticParams() {
  const published = await getPublishedProductLandingSlugs();
  if (published.length === 0) return [];
  const wanted = new Set(published);
  const [landingLocales, products] = await Promise.all([
    getPublishedProductLandingLocales(),
    prerenderLongTail(getProductUrls),
  ]);
  return products
    .filter((product) => wanted.has(product.slug))
    .flatMap(({ slug, slugs, locales }) => {
      const translated = landingLocales[slug] ?? [routing.defaultLocale];
      return locales
        .filter((locale) => translated.includes(locale))
        .map((locale) => ({ locale, slug: slugs[locale]! }));
    });
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  const config = await getSiteConfig();
  if (!isModuleEnabled(config, "product-landing")) return {};
  try {
    const route = await resolveEntityRoute(
      "product",
      "/products",
      slug,
      locale,
      (native) => getProductBySlug(native, locale),
    );
    if (!route) return {};
    const landing = await getProductLanding(route.nativeSlug, locale);
    if (!landing) return {};
    const product = route.entity;

    // Solo se anuncian los idiomas en los que existen la ficha Y la landing.
    const canonical = alternatesFor(`/products/${route.nativeSlug}/learn`, locale, {
      locales: (product.locales ?? [routing.defaultLocale]).filter((code) =>
        landing.locales.includes(code),
      ),
      paths: productLearnPaths(product.urlSlugs ?? {}),
    });
    const title = landing.seoTitle || product.name;
    const description = metaDescription(
      landing.seoDescription || product.shortDescription || landing.heroSubtitle,
    );
    const image =
      landing.seoOgImage || landing.heroImage || product.image?.sourceUrl || null;
    return {
      title: siteTitle(config, title),
      ...(description ? { description } : {}),
      alternates: canonical,
      openGraph: {
        ...siteOpenGraph(config, locale, canonical.canonical),
        type: "article",
        ...(image ? { images: [{ url: image }] } : {}),
      },
      twitter: {
        ...siteTwitter(config, locale),
        ...(image ? { images: [image] } : {}),
      },
    };
  } catch (error) {
    logReadFailure("landings.product_metadata_failed", error, {
      level: "warn",
      context: { slug, locale },
      message: "No se pudieron leer los metadatos de la landing de producto",
    });
    return {};
  }
}

export default async function ProductLearnPage({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale, slug } = await params;
  setRequestLocale(locale);

  const config = await getSiteConfig();
  if (!isModuleEnabled(config, "product-landing")) notFound();

  // Un fallo de WordPress NO es «no existe»: se propaga (500, que ISR no cachea)
  // en vez de hornear un 404 por una caída transitoria.
  const route = await resolveEntityRoute(
    "product",
    "/products",
    slug,
    locale,
    (native) => getProductBySlug(native, locale),
  );
  if (!route) notFound();
  const learnHref = `${route.canonicalHref}/learn`;
  if (!route.isCanonical) permanentRedirect({ href: learnHref, locale });
  const product = route.entity;

  const landing = await getProductLanding(route.nativeSlug, locale);
  if (!landing) notFound();

  const [t, tNav, related] = await Promise.all([
    getTranslations("productLanding"),
    getTranslations("nav"),
    getRelatedProductLandings(route.nativeSlug, locale, 3),
  ]);

  const url = absoluteLocalized(config.brand.url, learnHref, locale);
  // La ficha emite su propio `Product` con este `@id`: los motores fusionan ambas
  // menciones en una sola entidad.
  const pdpUrl = absoluteLocalized(config.brand.url, route.canonicalHref, locale);
  const description = metaDescription(
    landing.seoDescription || product.shortDescription || landing.heroSubtitle,
  );
  const faq = advicePageFaq(landing.advice);
  const videoId =
    landing.video.enabled && landing.video.url
      ? extractYoutubeId(landing.video.url)
      : null;
  const image = landing.heroImage || product.image?.sourceUrl || undefined;

  const jsonLd = [
    buildBreadcrumbJsonLd([
      { name: tNav("home"), url: absoluteLocalized(config.brand.url, "/", locale) },
      {
        name: t("breadcrumb"),
        url: absoluteLocalized(config.brand.url, "/products", locale),
      },
      { name: product.name, url: pdpUrl },
      { name: t("breadcrumb"), url },
    ]),
    buildArticleJsonLd({
      headline: landing.seoTitle || product.name,
      description,
      config,
      image,
      url,
      about: `${pdpUrl}#product`,
      inLanguage: landing.contentLang,
    }),
    buildProductJsonLd({
      product,
      config,
      url: pdpUrl,
      description: stripHtml(product.shortDescription ?? product.description ?? ""),
    }),
    ...(faq.length > 0 ? [buildFaqJsonLd(faq)] : []),
    ...(videoId
      ? [
          buildVideoObjectJsonLd({
            name: landing.video.caption || product.name,
            description: landing.video.caption || description || product.name,
            youtubeId: videoId,
            // Solo si el editor la escribió: sin fecha el nodo se emite igual
            // pero no opta al resultado enriquecido; una inventada lo invalidaría.
            ...(landing.video.uploadDate
              ? { uploadDate: landing.video.uploadDate }
              : {}),
          }),
        ]
      : []),
  ];

  return (
    <>
      {jsonLd.map((data, index) => (
        <script
          key={index}
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd(data) }}
        />
      ))}
      <LandingProductView
        product={product}
        landing={landing}
        productHref={productHref(product)}
        related={related}
        locale={locale}
      />
    </>
  );
}
