import { routing } from "@/i18n/routing";
import type { SiteConfig } from "@/lib/config/types";
import { viewOgImageUrl } from "./og-static";

// ============================================================================
//  Campos de sitio de la tarjeta social.
//
//  Next NO fusiona `openGraph` con el del layout: una página que declara el
//  suyo REEMPLAZA el objeto entero. Lo que se perdía en cada página que lo
//  declaraba —la ficha de producto, la de blog— eran justo los campos que
//  ninguna de ellas tiene por qué repetir: `og:site_name`, `og:locale` y
//  `og:locale:alternate` (y en Twitter, la cuenta del sitio). Sin
//  `og:site_name` la tarjeta de WhatsApp o LinkedIn no dice de qué sitio es el
//  enlace; sin `og:locale:alternate` nadie descubre la otra versión de idioma.
//
//  Lo que Next SÍ resuelve solo es el título y la descripción: si el objeto no
//  los trae, los toma de los de la propia página. Por eso estos helpers no los
//  incluyen — se spreadean y cada página añade encima lo suyo:
//
//    openGraph: { ...siteOpenGraph(config, locale, canonical), type: "article", images }
//
//  Viven aquí y no en el layout para que el layout sea un consumidor más.
// ============================================================================

export interface OgImage {
  url: string;
  width?: number;
  height?: number;
  alt?: string;
}

/**
 * Imagen social por defecto del sitio, por orden: la personalizada del panel
 * (SEO → OG Image, con `default_og: custom`), la tarjeta de marca de la home
 * que la instancia haya generado (`og-static.ts`) y la dinámica de `/api/og`.
 * La dinámica lleva `?locale=` fuera del locale canónico para que el tagline no
 * salga en el idioma equivocado al compartir una página de `/en`.
 */
export function siteOgImageUrl(config: SiteConfig, locale: string): string {
  if (config.seo.default_og === "custom" && config.seo.og_image) {
    return config.seo.og_image;
  }
  const brandCard = viewOgImageUrl("home", locale);
  if (brandCard) return brandCard;
  return locale === routing.defaultLocale ? "/api/og" : `/api/og?locale=${locale}`;
}

/**
 * La imagen por defecto, lista para `openGraph.images`. Las medidas solo se
 * declaran para las tarjetas generadas, que SÍ miden 1200×630: la personalizada
 * es un archivo de la medioteca de tamaño desconocido, y declararle 1200×630 es
 * mentir (Facebook y LinkedIn recortan según las medidas declaradas). Sin
 * medidas, las leen del archivo.
 */
export function siteOgImage(config: SiteConfig, locale: string): OgImage {
  const url = siteOgImageUrl(config, locale);
  const isCustom = config.seo.default_og === "custom" && Boolean(config.seo.og_image);
  return {
    url,
    ...(isCustom ? {} : { width: 1200, height: 630 }),
    alt: config.brand.name,
  };
}

/** Territorio por defecto de cada idioma que no es el canónico del sitio. */
const OG_LOCALE_FALLBACK: Record<string, string> = {
  en: "en_US",
  es: "es_ES",
  pt: "pt_BR",
  fr: "fr_FR",
  de: "de_DE",
  it: "it_IT",
};

/**
 * `og:locale` en la forma que exige Open Graph, `idioma_TERRITORIO` (`es_CO`):
 * un `es` suelto no es un valor válido y el depurador de Facebook lo marca. El
 * idioma canónico lleva el país de la tienda (`ecommerce.country`); los demás,
 * su territorio habitual.
 */
export function ogLocale(locale: string, country: string): string {
  if (locale === routing.defaultLocale && /^[A-Z]{2}$/.test(country)) {
    return `${locale}_${country}`;
  }
  return OG_LOCALE_FALLBACK[locale] ?? locale;
}

/**
 * Campos de SITIO de `openGraph`. Se spreadean primero; lo que la página ponga
 * después manda (típicamente `type` y su propia `images`).
 *
 * `url` es la canónica de la página (`alternates.canonical`): `og:url` es una de
 * las cuatro propiedades obligatorias de Open Graph y el layout no puede
 * ponerla, porque no conoce la ruta. `type` es `website` salvo que la página
 * diga otra cosa.
 */
export function siteOpenGraph(config: SiteConfig, locale: string, url?: string) {
  return {
    type: "website" as const,
    ...(url ? { url } : {}),
    siteName: config.brand.name,
    locale: ogLocale(locale, config.ecommerce.country),
    alternateLocale: routing.locales
      .filter((l) => l !== locale)
      .map((l) => ogLocale(l, config.ecommerce.country)),
    images: [siteOgImage(config, locale)],
  };
}

/** Lo mismo para la tarjeta de Twitter/X. */
export function siteTwitter(config: SiteConfig, locale: string) {
  return {
    card: "summary_large_image" as const,
    creator: config.social.twitter || undefined,
    images: [siteOgImageUrl(config, locale)],
  };
}
