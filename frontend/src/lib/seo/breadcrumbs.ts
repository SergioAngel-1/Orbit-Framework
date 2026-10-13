import { buildBreadcrumbJsonLd } from "./jsonld";
import { absoluteLocalized } from "./urls";
import type { SiteConfig } from "@/lib/config/types";

// ============================================================================
//  `BreadcrumbList` de una vista de segundo nivel: Inicio › esta página.
//
//  El árbol de la tienda ya emite el suyo, con su ruta real (Inicio › Tienda ›
//  Categoría › Producto). Esto es la miga corta del resto de vistas
//  indexables —blog, contacto, nosotros, legales—. Es el MISMO builder; lo
//  único que añade es no repetir en cada página el `absoluteLocalized` de cada
//  tramo y la miga de Inicio.
//
//  Las URLs son absolutas y localizadas: el tramo de Inicio es `/` en el idioma
//  por defecto y `/en` en inglés.
// ============================================================================

export interface PageCrumb {
  /** Rótulo visible. El del menú cuando existe: es el nombre corto de la sección. */
  name: string;
  /** Ruta interna (`/blog`, `/legal/terms`). */
  href: string;
}

export function buildPageBreadcrumbJsonLd(
  config: SiteConfig,
  locale: string,
  homeLabel: string,
  crumbs: PageCrumb[],
): Record<string, unknown> {
  return buildBreadcrumbJsonLd([
    { name: homeLabel, url: absoluteLocalized(config.brand.url, "/", locale) },
    ...crumbs.map((crumb) => ({
      name: crumb.name,
      url: absoluteLocalized(config.brand.url, crumb.href, locale),
    })),
  ]);
}
