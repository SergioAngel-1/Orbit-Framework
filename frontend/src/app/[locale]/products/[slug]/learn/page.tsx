import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { permanentRedirect } from "@/i18n/navigation";
import { routing } from "@/i18n/routing";
import {
  EditorialLandingContent,
  heroTitleOf,
} from "@/components/landings/editorial/editorial-landing-route";
import { LandingProductView } from "@/components/landings/product/landing-product-view";
import { getProductBySlug, getProductUrls } from "@/lib/catalog/products";
import { productHref } from "@/lib/catalog/product-href";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { stripHtml } from "@/lib/format";
import { resolveEntityRoute } from "@/lib/i18n/entity-route";
import { advicePageFaq } from "@/lib/landings/advice";
import { resolveCmsLink } from "@/lib/landings/editorial-href";
import { getEditorialLanding } from "@/lib/landings/editorial-landing";
import { getEditorialLandingForProduct } from "@/lib/landings/editorial-product";
import {
  getProductBoundLandingLocales,
  getProductBoundLandingSlugs,
} from "@/lib/landings/product-bound-landings";
import { getProductLanding } from "@/lib/landings/product-landing";
import { productLandingEntry } from "@/lib/landings/product-landing-entry";
import { productLearnPaths } from "@/lib/landings/product-learn-href";
import { buildEditorialRegistry } from "@/lib/landings/registry";
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
//  Landing de un producto — `/products/[slug]/learn`.
//
//  Cuelga de la ficha, así que comparte su tramo de URL por idioma y su 308 hacia
//  el canónico. La sirve UNO de dos mecanismos, con esta precedencia:
//
//    1. la landing de producto publicada (módulo `product-landing`, caja de la
//       ficha);
//    2. una landing editorial asociada a ese producto (módulo `landings`, campo
//       «Producto asociado»).
//
//  Si ninguno aplica —o los módulos están apagados— es 404.
//
//  ISR igual que la ficha. `generateStaticParams` solo enumera las landings
//  publicadas, en los idiomas en que existen (sin traducción no se hornea ni se
//  anuncia ese idioma).
// ============================================================================

export const revalidate = 300;

export async function generateStaticParams() {
  const published = await getProductBoundLandingSlugs();
  if (published.size === 0) return [];
  const [landingLocales, products] = await Promise.all([
    getProductBoundLandingLocales(),
    prerenderLongTail(getProductUrls),
  ]);
  return products
    .filter((product) => published.has(product.slug))
    .flatMap(({ slug, slugs, locales }) => {
      const translated = landingLocales[slug] ?? [routing.defaultLocale];
      return locales
        .filter((locale) => translated.includes(locale))
        .map((locale) => ({ locale, slug: slugs[locale]! }));
    });
}

/** Las dos landings posibles de un producto, la que manda primero. */
async function loadLandings(
  nativeSlug: string,
  locale: string,
  config: Awaited<ReturnType<typeof getSiteConfig>>,
) {
  const productLanding = isModuleEnabled(config, "product-landing")
    ? await getProductLanding(nativeSlug, locale)
    : null;
  if (productLanding) return { productLanding, editorial: null };

  if (!isModuleEnabled(config, "landings"))
    return { productLanding: null, editorial: null };
  const link = await getEditorialLandingForProduct(nativeSlug, locale);
  const editorial = link ? await getEditorialLanding(link.slug, locale) : null;
  return { productLanding: null, editorial };
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  const config = await getSiteConfig();
  if (
    !isModuleEnabled(config, "product-landing") &&
    !isModuleEnabled(config, "landings")
  ) {
    return {};
  }
  try {
    const route = await resolveEntityRoute(
      "product",
      "/products",
      slug,
      locale,
      (native) => getProductBySlug(native, locale),
    );
    if (!route) return {};
    const { productLanding, editorial } = await loadLandings(
      route.nativeSlug,
      locale,
      config,
    );
    const landing = productLanding ?? editorial;
    if (!landing) return {};
    const product = route.entity;

    // Solo se anuncian los idiomas en los que existen la ficha Y la landing.
    const canonical = alternatesFor(`/products/${route.nativeSlug}/learn`, locale, {
      locales: (product.locales ?? [routing.defaultLocale]).filter((code) =>
        landing.locales.includes(code),
      ),
      paths: productLearnPaths(product.urlSlugs ?? {}),
    });

    const title = productLanding
      ? productLanding.seoTitle || product.name
      : editorial!.seo.title || editorial!.hero?.title || product.name;
    const description = metaDescription(
      productLanding
        ? productLanding.seoDescription ||
            product.shortDescription ||
            productLanding.heroSubtitle
        : editorial!.seo.description ||
            product.shortDescription ||
            editorial!.hero?.subtitle,
    );
    const image =
      (productLanding
        ? productLanding.seoOgImage || productLanding.heroImage
        : editorial!.seo.ogImage || editorial!.hero?.image) ||
      product.image?.sourceUrl ||
      null;
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
  if (
    !isModuleEnabled(config, "product-landing") &&
    !isModuleEnabled(config, "landings")
  ) {
    notFound();
  }

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

  const { productLanding: landing, editorial } = await loadLandings(
    route.nativeSlug,
    locale,
    config,
  );
  if (!landing && !editorial) notFound();

  const [t, tNav] = await Promise.all([
    getTranslations("productLanding"),
    getTranslations("nav"),
  ]);

  const url = absoluteLocalized(config.brand.url, learnHref, locale);
  // La ficha emite su propio `Product` con este `@id`: los motores fusionan ambas menciones en
  // una sola entidad.
  const pdpUrl = absoluteLocalized(config.brand.url, route.canonicalHref, locale);
  const productJsonLd = buildProductJsonLd({
    product,
    config,
    url: pdpUrl,
    description: stripHtml(product.shortDescription ?? product.description ?? ""),
  });
  const breadcrumb = [
    { name: tNav("home"), url: absoluteLocalized(config.brand.url, "/", locale) },
    {
      name: t("breadcrumb"),
      url: absoluteLocalized(config.brand.url, "/products", locale),
    },
    { name: product.name, url: pdpUrl },
    { name: t("breadcrumb"), url },
  ];

  // Mecanismo 2: landing editorial asociada. Se pinta con el motor de landings; el `Product` de
  // la ficha se suma a su JSON-LD.
  if (!landing && editorial) {
    const registry = buildEditorialRegistry(config.landings.registry);
    const entry = productLandingEntry(registry, editorial.slug, product.name);
    return (
      <EditorialLandingContent
        locale={locale}
        config={config}
        entry={entry}
        landing={{
          ...editorial,
          // Sin título propio, la landing lleva el nombre del producto.
          hero: editorial.hero ?? null,
          seo: { ...editorial.seo, title: editorial.seo.title ?? product.name },
        }}
        url={url}
        breadcrumb={[
          ...breadcrumb.slice(0, 3),
          { name: heroTitleOf(editorial, { ...entry, label: product.name }), url },
        ]}
        extraJsonLd={[productJsonLd]}
      />
    );
  }

  // Mecanismo 1: landing de producto.
  const productLanding = landing!;
  const [related, closingLink] = await Promise.all([
    getRelatedProductLandings(route.nativeSlug, locale, 3),
    resolveCmsLink(productLanding.closing.ctaHref, locale),
  ]);

  const description = metaDescription(
    productLanding.seoDescription ||
      product.shortDescription ||
      productLanding.heroSubtitle,
  );
  const faq = advicePageFaq(productLanding.advice);
  const videoId =
    productLanding.video.enabled && productLanding.video.url
      ? extractYoutubeId(productLanding.video.url)
      : null;
  const image = productLanding.heroImage || product.image?.sourceUrl || undefined;

  const jsonLd = [
    buildBreadcrumbJsonLd(breadcrumb),
    buildArticleJsonLd({
      headline: productLanding.seoTitle || product.name,
      description,
      config,
      image,
      url,
      about: `${pdpUrl}#product`,
      inLanguage: productLanding.contentLang,
    }),
    productJsonLd,
    ...(faq.length > 0 ? [buildFaqJsonLd(faq)] : []),
    ...(videoId
      ? [
          buildVideoObjectJsonLd({
            name: productLanding.video.caption || product.name,
            description: productLanding.video.caption || description || product.name,
            youtubeId: videoId,
            // Solo si el editor la escribió: sin fecha el nodo se emite igual pero no opta al
            // resultado enriquecido; una inventada lo invalidaría.
            ...(productLanding.video.uploadDate
              ? { uploadDate: productLanding.video.uploadDate }
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
        landing={productLanding}
        productHref={productHref(product)}
        related={related}
        closingLink={closingLink}
        locale={locale}
      />
    </>
  );
}
