import "server-only";
import { cache } from "react";
import { routing } from "@/i18n/routing";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { fetchGraphQL } from "@/lib/graphql-client";
import { fetchLocalizedGraphQL } from "@/lib/i18n/fetch-localized";
import { pickTranslated, secondaryLocales } from "@/lib/i18n/translation";
import { logReadFailure } from "@/lib/observability/read-failure";
import { sanitizeHtml } from "@/lib/security/sanitize";
import { isoDateOrMonth } from "@/lib/landings/iso-date";
import {
  landingContentLocale,
  landingTranslatedLocales,
} from "@/lib/landings/localized";
import {
  LANDING_LOCALES_QUERY,
  PRODUCT_LANDING_QUERY,
  PRODUCT_LANDINGS_PUBLISHED_QUERY,
} from "@/lib/landings/queries";
import type { ProductLanding } from "@/lib/landings/types";

// ============================================================================
//  Lectores de la landing de producto (módulo `product-landing`).
//
//  Con `modules.product-landing = false` ningún lector llama a WordPress: el
//  módulo no existe a efectos del frontend (el plugin tampoco registra
//  `Product.landing`, y pedirlo rompería la consulta).
// ============================================================================

const REVALIDATE = 3600;
/** Slugs por consulta de lote (WPGraphQL pagina a 100). */
const BATCH = 50;

async function moduleEnabled(): Promise<boolean> {
  return isModuleEnabled(await getSiteConfig(), "product-landing");
}

/**
 * Slugs de producto con landing publicada. Para `generateStaticParams`, el
 * sitemap y la ficha (el enlace a la landing): 1 fetch en vez de resolver
 * `getProductLanding` producto a producto (N+1) solo para saber si está
 * publicada.
 *
 * Degrada a `[]` y queda en el log: el sitemap sigue emitiendo el resto de URLs
 * y las rutas `learn` caen a ISR bajo demanda, sin romper el build.
 */
export const getPublishedProductLandingSlugs = cache(async (): Promise<string[]> => {
  if (!(await moduleEnabled())) return [];
  try {
    const data = await fetchGraphQL<{
      hweProductLandingsPublished: (string | null)[] | null;
    }>(PRODUCT_LANDINGS_PUBLISHED_QUERY, {
      revalidate: REVALIDATE,
      tags: ["products"],
    });
    return (data.hweProductLandingsPublished ?? []).filter(
      (slug): slug is string => typeof slug === "string" && slug !== "",
    );
  } catch (error) {
    logReadFailure("landings.published_slugs_failed", error, {
      message:
        "No se pudieron leer las landings de producto publicadas: no se enlazan ni se anuncian",
    });
    return [];
  }
});

/**
 * Idiomas en los que existe la landing de cada producto publicado
 * (`slug → idiomas`). Un producto que no aparece (lectura fallida) se trata
 * como existente solo en el idioma por defecto: sin verificar, no se anuncia.
 */
export const getPublishedProductLandingLocales = cache(
  async (): Promise<Record<string, string[]>> => {
    const slugs = await getPublishedProductLandingSlugs();
    const out: Record<string, string[]> = {};
    for (let i = 0; i < slugs.length; i += BATCH) {
      const batch = slugs.slice(i, i + BATCH);
      try {
        const data = await fetchLocalizedGraphQL<{
          products: { nodes: { slug: string; landing: object | null }[] } | null;
        }>(LANDING_LOCALES_QUERY, secondaryLocales(), {
          variables: { slugs: batch },
          revalidate: REVALIDATE,
          tags: ["products"],
        });
        for (const node of data.products?.nodes ?? []) {
          if (node.landing) out[node.slug] = landingTranslatedLocales(node.landing);
        }
      } catch (error) {
        logReadFailure("landings.locales_failed", error, {
          context: { count: batch.length },
          message:
            "No se pudieron leer los idiomas de las landings de producto: solo se anuncia el idioma por defecto",
        });
      }
    }
    return out;
  },
);

/**
 * ¿Hay landing publicada del producto `slug` (nativo) para enlazarla desde
 * `locale`? El idioma por defecto basta con estar publicada; uno secundario
 * exige además que la landing esté traducida (sin traducción no se anuncia ese
 * idioma). Una lectura fallida da `false`: sin verificar, no se enlaza.
 */
export async function hasProductLanding(
  slug: string,
  locale: string,
): Promise<boolean> {
  const published = await getPublishedProductLandingSlugs();
  if (!published.includes(slug)) return false;
  if (locale === routing.defaultLocale) return true;
  return (await getPublishedProductLandingLocales())[slug]?.includes(locale) ?? false;
}

/** `Product.landing` tal como llega de WPGraphQL tras aplanar las traducciones. */
export type RawLanding = Record<string, unknown>;

interface RawResponse {
  product: { landing: RawLanding | null } | null;
}

/**
 * La landing del producto de slug NATIVO `slug`, resuelta al idioma. Nula si el
 * módulo está apagado, el producto no existe o la landing no está publicada. Un
 * fallo de WordPress se propaga: convertirlo en «no hay landing» hornearía un
 * 404 en la caché ISR.
 */
export const getProductLanding = cache(
  async (slug: string, locale: string): Promise<ProductLanding | null> => {
    if (!(await moduleEnabled())) return null;
    // Se piden las traducciones de TODOS los idiomas: el hreflang de la página
    // declara en cuáles existe, y la misma consulta sirve a cualquier idioma.
    const data = await fetchLocalizedGraphQL<RawResponse>(
      PRODUCT_LANDING_QUERY,
      secondaryLocales(),
      { variables: { slug }, revalidate: REVALIDATE, tags: ["products"] },
    );
    return normalizeProductLanding(data.product?.landing ?? null, locale);
  },
);

const text = (value: unknown): string | null =>
  typeof value === "string" && value.trim() !== "" ? value : null;

const HEX = /^#[0-9a-f]{6}$/i;
/** Hex `#rrggbb` estricto o `null`: se inyecta como propiedad CSS. */
const hex = (value: unknown): string | null =>
  typeof value === "string" && HEX.test(value.trim())
    ? value.trim().toLowerCase()
    : null;

const rows = (value: unknown): Record<string, unknown>[] =>
  Array.isArray(value)
    ? value.filter(
        (row): row is Record<string, unknown> => !!row && typeof row === "object",
      )
    : [];

/**
 * Normaliza `Product.landing` al modelo `ProductLanding` resuelto al idioma.
 * Nula si no hay landing o no está publicada. Pura salvo por el saneado.
 */
export function normalizeProductLanding(
  raw: RawLanding | null | undefined,
  locale: string,
): ProductLanding | null {
  if (!raw || raw.published !== true) return null;

  const t = (field: string): string | null => text(pickTranslated(raw, field, locale));
  const html = (field: string): string | null => {
    const value = t(field);
    return value ? sanitizeHtml(value) : null;
  };

  return {
    contentLang: landingContentLocale(raw, locale),
    locales: landingTranslatedLocales(raw),
    seoTitle: t("seoTitle"),
    seoDescription: t("seoDescription"),
    seoOgImage: text(raw.seoOgImage),
    accents: { primary: hex(raw.accent1), secondary: hex(raw.accent2) },
    heroSubtitle: t("heroSubtitle"),
    heroImage: text(raw.heroImage),
    edu: {
      enabled: raw.sectionEduEnabled === true,
      kicker: t("eduKicker"),
      title: t("eduTitle"),
      body: html("eduBody"),
      image: text(raw.eduImage),
    },
    about: {
      title: t("aboutTitle"),
      body: html("aboutBody"),
      components: rows(raw.components).map((row) => ({
        image: text(row.image),
        icon: text(row.icon),
        name: text(pickTranslated(row, "name", locale)) ?? "",
        note: text(pickTranslated(row, "note", locale)),
      })),
    },
    qualities: {
      enabled: raw.sectionQualitiesEnabled === true,
      title: t("qualitiesTitle"),
      items: rows(raw.qualities).map((row) => ({
        icon: text(row.icon),
        image: text(row.image),
        title: text(pickTranslated(row, "title", locale)) ?? "",
        text: text(pickTranslated(row, "text", locale)),
      })),
    },
    advice: {
      enabled: raw.sectionAdviceEnabled === true,
      title: t("adviceTitle"),
      intro: html("adviceIntro"),
      // Toda fila necesita cuerpo: sin él, un consejo es un título pelado y una
      // pregunta es un acordeón que no revela nada (y quedaría fuera del
      // `FAQPage`, descuadrando el marcado y el contenido visible). El cuerpo
      // es texto plano: el plugin elimina el HTML al guardar.
      items: rows(raw.adviceItems)
        .map((row) => ({
          heading: text(pickTranslated(row, "heading", locale)),
          body: (text(pickTranslated(row, "body", locale)) ?? "").trim(),
        }))
        .filter((row) => row.body !== ""),
    },
    cta: { title: t("ctaTitle"), text: t("ctaText") },
    video: {
      enabled: raw.sectionVideoEnabled === true,
      url: text(raw.videoUrl),
      caption: t("videoCaption"),
      // Admite `AAAA-MM`: el editor suele recordar el mes y no el día. Ver `isoDateOrMonth`.
      uploadDate: isoDateOrMonth(raw.videoDate),
      poster: text(raw.videoPoster),
    },
    closing: {
      title: t("closingTitle"),
      text: html("closingText"),
      ctaLabel: t("closingCtaLabel"),
      ctaHref: t("closingCtaHref"),
    },
  };
}
