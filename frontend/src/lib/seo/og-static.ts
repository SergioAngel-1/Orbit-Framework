import { routing } from "@/i18n/routing";
import { instanceRegistry } from "@/instance/registry";
import type { OgImage } from "./opengraph";

// ============================================================================
//  Tarjetas sociales ESTÁTICAS de las vistas principales (opcionales).
//
//  Por defecto el sitio comparte la tarjeta dinámica de marca (`/api/og`). Una
//  instancia que quiere arte propio por vista genera los PNG (1200×630, uno por
//  vista e idioma) con `npm run og:generate`: el script los escribe en
//  `public/og/` y deja en `src/instance/og-cards.json` el manifiesto
//  `"<vista>.<idioma>" → hash del archivo`, que el registro de la instancia
//  (`src/instance/registry.ts`) entrega a este módulo.
//
//  El hash viaja en la URL (`?v=`): las redes cachean la imagen social por URL,
//  y sin él una tarjeta regenerada seguiría saliendo con el arte viejo.
//
//  `OG_VIEWS` tiene que coincidir con las claves de `VIEWS` del script
//  (`og-static.test.ts` lo comprueba).
// ============================================================================

export const OG_VIEWS = ["home", "products", "blog", "about", "contact"] as const;

export type OgView = (typeof OG_VIEWS)[number];

function cards(): Record<string, string | undefined> {
  return instanceRegistry.ogCards ?? {};
}

/** URL pública de la tarjeta de una vista, o `null` si la instancia no la ha generado. */
export function viewOgImageUrl(view: OgView, locale: string): string | null {
  const hashes = cards();
  const key = hashes[`${view}.${locale}`]
    ? `${view}.${locale}`
    : `${view}.${routing.defaultLocale}`;
  const hash = hashes[key];
  return hash ? `/og/${key}.png?v=${hash}` : null;
}

/**
 * La tarjeta de una vista, lista para `openGraph.images`. Las medidas se
 * declaran porque aquí SÍ se conocen: el script siempre pinta 1200×630.
 */
export function viewOgImage(view: OgView, locale: string, alt: string): OgImage | null {
  const url = viewOgImageUrl(view, locale);
  return url ? { url, width: 1200, height: 630, alt } : null;
}

/**
 * `openGraph.images` y `twitter.images` de una vista, para spreadear DESPUÉS de
 * `siteOpenGraph` / `siteTwitter`. Sin tarjeta generada devuelve objetos vacíos
 * y la vista se queda con la imagen por defecto del sitio.
 */
export function viewSocialImages(view: OgView, locale: string, alt: string) {
  const image = viewOgImage(view, locale, alt);
  return {
    openGraph: image ? { images: [image] } : {},
    twitter: image ? { images: [image.url] } : {},
  };
}
