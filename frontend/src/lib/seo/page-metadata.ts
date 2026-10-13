import type { Metadata } from "next";
import type { SiteConfig } from "@/lib/config/types";
import { metaDescription } from "./meta-description";
import { siteOpenGraph, siteTwitter } from "./opengraph";
import { viewSocialImages, type OgView } from "./og-static";
import { siteTitle } from "./title";
import { alternatesFor, type AlternatesOptions } from "./urls";

// ============================================================================
//  Metadatos de una página indexable estándar.
//
//  Reúne lo que CADA página tiene que declarar y que antes se copiaba (y se
//  olvidaba) a mano: título con la plantilla del sitio, descripción recortada
//  por palabra completa, canónica + hreflang, y los campos de sitio de Open
//  Graph / Twitter. Una página con necesidades propias (ficha de producto,
//  entrada del blog) compone `siteOpenGraph` ella misma.
// ============================================================================

export interface PageMetadataInput {
  config: SiteConfig;
  locale: string;
  /** Ruta interna (`/about`, `/legal/privacy`). */
  path: string;
  title: string;
  /** HTML o texto; se recorta a la meta description. Vacío = hereda la del sitio. */
  description?: string | null;
  /** Vista con tarjeta social estática propia (`og-static`), si la instancia la generó. */
  view?: OgView;
  alternates?: AlternatesOptions;
}

export function pageMetadata({
  config,
  locale,
  path,
  title,
  description,
  view,
  alternates,
}: PageMetadataInput): Metadata {
  const canonical = alternatesFor(path, locale, alternates);
  const text = metaDescription(description);
  const cards = view ? viewSocialImages(view, locale, title) : null;
  return {
    title: siteTitle(config, title),
    ...(text ? { description: text } : {}),
    alternates: canonical,
    openGraph: {
      ...siteOpenGraph(config, locale, canonical.canonical),
      ...(cards?.openGraph ?? {}),
    },
    twitter: { ...siteTwitter(config, locale), ...(cards?.twitter ?? {}) },
  };
}

/** Vista privada o sin valor para un buscador: sin `alternates` ni tarjeta social. */
export function privateMetadata(title?: string): Metadata {
  return {
    ...(title ? { title } : {}),
    robots: { index: false, follow: false },
  };
}
