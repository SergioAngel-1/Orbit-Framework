import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { permanentRedirect } from "@/i18n/navigation";
import { LandingEditorialView } from "@/components/landings/editorial/landing-editorial-view";
import { renderUniqueSections } from "@/components/landings/editorial/unique-sections";
import { getSiteConfig } from "@/lib/config";
import type { SiteConfig } from "@/lib/config/types";
import { isModuleEnabled } from "@/lib/config/modules";
import type { EditorialLanding } from "@/lib/landings/types";
import { getEditorialHrefStrict, resolveCmsLink } from "@/lib/landings/editorial-href";
import { getEditorialLanding } from "@/lib/landings/editorial-landing";
import { editorialTranslatedLocales } from "@/lib/landings/editorial-locales";
import {
  buildEditorialRegistry,
  editorialLandingHref,
  editorialLandingPaths,
  findEditorialByAnyPath,
  pathFor,
  type EditorialRegistryEntry,
  type EditorialUrlBase,
} from "@/lib/landings/registry";
import { metaDescription } from "@/lib/seo/meta-description";
import { siteOpenGraph, siteTwitter } from "@/lib/seo/opengraph";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { siteTitle } from "@/lib/seo/title";
import { absoluteLocalized, alternatesFor } from "@/lib/seo/urls";
import {
  buildArticleJsonLd,
  buildBreadcrumbJsonLd,
  buildFaqJsonLd,
  buildPersonJsonLdFor,
} from "@/lib/seo/jsonld";

// ============================================================================
//  Lógica común de las dos rutas de una landing editorial:
//
//    · `/learn/[slug]`        → `base: "area"`
//    · `/[landingPath]`       → `base: "root"`
//
//  Las dos delegan aquí: resolver el tramo (con el de cualquier idioma, y 308 al
//  canónico), 404 si el módulo está apagado o la landing no está publicada,
//  metadatos con hreflang SOLO de los idiomas con traducción, JSON-LD
//  (`BreadcrumbList`, `Article` o `Person`, `FAQPage`) y la vista.
//
//  Las secciones únicas de una landing con diseño propio las pasa la instancia
//  como `uniqueSections` (clave → nodo): este archivo es del starter, y la
//  instancia lo extiende o lo sustituye.
// ============================================================================

/** Una landing registrada y servida por un tramo de URL, o `null`. */
async function resolveEntry(locale: string, base: EditorialUrlBase, path: string) {
  const config = await getSiteConfig();
  if (!isModuleEnabled(config, "landings")) return null;
  const registry = buildEditorialRegistry(
    config.landings.registry,
    config.landings.editorial,
  );
  const found = findEditorialByAnyPath(registry, base, path, locale);
  return found ? { config, ...found } : null;
}

/**
 * `generateStaticParams`: una entrada por landing PUBLICADA de esta ubicación y por idioma en el
 * que existe, con su tramo. Sin módulo o sin landings, vacío (ISR bajo demanda).
 */
export async function editorialStaticParams(base: EditorialUrlBase, param: string) {
  const config = await getSiteConfig();
  if (!isModuleEnabled(config, "landings")) return [];
  const registry = buildEditorialRegistry(
    config.landings.registry,
    config.landings.editorial,
  );
  return Object.values(registry).flatMap((entry) => {
    const cfg = config.landings.editorial[entry.slug];
    if (entry.base !== base || cfg?.published !== true) return [];
    return editorialTranslatedLocales(cfg).map((locale) => ({
      locale,
      [param]: pathFor(entry, locale),
    }));
  });
}

export function heroTitleOf(
  landing: EditorialLanding,
  entry: EditorialRegistryEntry,
): string {
  return landing.hero?.title || landing.seo.title || entry.label;
}

export async function editorialMetadata(
  locale: string,
  base: EditorialUrlBase,
  path: string,
): Promise<Metadata> {
  const resolved = await resolveEntry(locale, base, path);
  if (!resolved) return {};
  const landing = await getEditorialLanding(resolved.entry.slug, locale);
  if (!landing) return {};

  const alternates = alternatesFor(editorialLandingHref(resolved.entry), locale, {
    locales: landing.locales,
    paths: editorialLandingPaths(resolved.entry),
  });
  const title = landing.seo.title || heroTitleOf(landing, resolved.entry);
  const description = metaDescription(
    landing.seo.description || landing.hero?.subtitle,
  );
  const image = landing.seo.ogImage || landing.hero?.image || null;

  return {
    title: siteTitle(resolved.config, title),
    ...(description ? { description } : {}),
    alternates,
    openGraph: {
      ...siteOpenGraph(resolved.config, locale, alternates.canonical),
      type: "article",
      ...(image ? { images: [{ url: image }] } : {}),
    },
    twitter: {
      ...siteTwitter(resolved.config, locale),
      ...(image ? { images: [image] } : {}),
    },
  };
}

/**
 * El contenido de una landing editorial: JSON-LD (`BreadcrumbList`, `Article` o `Person`,
 * `FAQPage` y lo extra que pase quien la sirve) y la vista. Lo comparten la ruta de URL libre y la
 * de una landing asociada a un producto (`/products/[slug]/learn`).
 */
export async function EditorialLandingContent({
  locale,
  config,
  entry,
  landing,
  url,
  breadcrumb,
  extraJsonLd = [],
}: {
  locale: string;
  config: SiteConfig;
  entry: EditorialRegistryEntry;
  landing: EditorialLanding;
  /** URL absoluta y localizada de la página. */
  url: string;
  breadcrumb: { name: string; url: string }[];
  /** Nodos JSON-LD adicionales (p. ej. el `Product` de la ficha). */
  extraJsonLd?: Record<string, unknown>[];
}) {
  const [primary, secondary, authorHref] = await Promise.all([
    resolveCmsLink(landing.closing?.ctaHref, locale),
    resolveCmsLink(landing.closing?.cta2Href, locale),
    landing.seo.author?.landingSlug
      ? getEditorialHrefStrict(landing.seo.author.landingSlug, locale)
      : Promise.resolve(null),
  ]);

  const heroTitle = heroTitleOf(landing, entry);
  const description = metaDescription(
    landing.seo.description || landing.hero?.subtitle,
  );
  const image = landing.seo.ogImage || landing.hero?.image || undefined;
  const author = landing.seo.author;

  const jsonLd: Record<string, unknown>[] = [buildBreadcrumbJsonLd(breadcrumb)];
  if (entry.jsonLd === "person") {
    jsonLd.push({
      ...buildPersonJsonLdFor({ name: heroTitle, image, url }, config),
      mainEntityOfPage: url,
      ...(description ? { description } : {}),
    });
  } else {
    jsonLd.push(
      buildArticleJsonLd({
        headline: heroTitle,
        description,
        config,
        image,
        url,
        inLanguage: landing.contentLang,
        ...(landing.seo.datePublished
          ? { datePublished: landing.seo.datePublished }
          : {}),
        ...(landing.seo.dateModified ? { dateModified: landing.seo.dateModified } : {}),
        ...(author
          ? {
              author: {
                name: author.name,
                ...(authorHref
                  ? { url: absoluteLocalized(config.brand.url, authorHref, locale) }
                  : {}),
              },
            }
          : {}),
      }),
    );
  }
  jsonLd.push(...extraJsonLd);
  if (landing.faq && landing.faq.items.length > 0) {
    jsonLd.push(
      buildFaqJsonLd(
        landing.faq.items.map((item) => ({
          question: item.question,
          // El FAQPage declara texto plano: lo que se ve y lo que se declara coinciden.
          answer: item.answer
            .replace(/<[^>]*>/g, " ")
            .replace(/\s+/g, " ")
            .trim(),
        })),
      ),
    );
  }

  return (
    <>
      {jsonLd.map((data, index) => (
        <script
          key={index}
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd(data) }}
        />
      ))}
      <LandingEditorialView
        landing={landing}
        entry={entry}
        heroTitle={heroTitle}
        locale={locale}
        currency={config.ecommerce.currency}
        decimals={config.ecommerce.price_decimals}
        closingLinks={{ primary, secondary }}
        uniqueSections={renderUniqueSections({ landing, entry, locale })}
      />
    </>
  );
}

export async function EditorialLandingRoute({
  locale,
  base,
  path,
}: {
  locale: string;
  base: EditorialUrlBase;
  path: string;
}) {
  setRequestLocale(locale);

  const resolved = await resolveEntry(locale, base, path);
  if (!resolved) notFound();
  const { config, entry, canonical } = resolved;

  const href = editorialLandingHref(entry, locale);
  // El tramo pedido puede ser el de otro idioma: 308 al del idioma de la página.
  if (!canonical) permanentRedirect({ href, locale });

  // Un fallo de WordPress NO es «no existe»: se propaga (500, que ISR no cachea).
  const landing = await getEditorialLanding(entry.slug, locale);
  if (!landing) notFound();

  const [t, tNav] = await Promise.all([
    getTranslations("landings"),
    getTranslations("nav"),
  ]);
  const url = absoluteLocalized(config.brand.url, href, locale);

  return (
    <EditorialLandingContent
      locale={locale}
      config={config}
      entry={entry}
      landing={landing}
      url={url}
      breadcrumb={[
        { name: tNav("home"), url: absoluteLocalized(config.brand.url, "/", locale) },
        ...(entry.base === "area"
          ? [
              {
                name: t("breadcrumb"),
                url: absoluteLocalized(config.brand.url, "/learn", locale),
              },
            ]
          : []),
        { name: heroTitleOf(landing, entry), url },
      ]}
    />
  );
}
