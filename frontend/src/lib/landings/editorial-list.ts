import type { SiteConfig } from "@/lib/config/types";
import { isModuleEnabled } from "@/lib/config/modules";
import { pickLocalized } from "@/lib/i18n/pick-localized";
import { editorialTranslatedLocales } from "@/lib/landings/editorial-locales";
import { isoDate } from "@/lib/landings/iso-date";
import {
  buildEditorialRegistry,
  editorialLandingHref,
  editorialLandingPaths,
  type EditorialUrlBase,
} from "@/lib/landings/registry";

// ============================================================================
//  Las landings editoriales PUBLICADAS que existen en un idioma, listas para
//  enlazar: el índice del área (`/learn`) y `/llms.txt`.
//
//  Solo cuenta lo que se sirve por URL libre: una landing asociada a un producto
//  vive en la URL de ese producto y la lista la índice de productos. Y solo en
//  los idiomas en que existe (sin traducción no se anuncia el idioma).
//
//  Módulo PURO: lee la config ya cargada.
// ============================================================================

export interface PublishedEditorialLanding {
  slug: string;
  title: string;
  description: string | null;
  /** Ruta interna en `locale`, lista para `Link` y `absoluteLocalized`. */
  href: string;
  base: EditorialUrlBase;
}

const text = (value: unknown): string | null =>
  typeof value === "string" && value.trim() !== "" ? value.trim() : null;

export function publishedEditorialLandings(
  config: Pick<SiteConfig, "landings" | "modules">,
  locale: string,
  base?: EditorialUrlBase,
): PublishedEditorialLanding[] {
  if (!config.landings || !isModuleEnabled(config, "landings")) return [];
  const registry = buildEditorialRegistry(
    config.landings.registry,
    config.landings.editorial,
  );
  return Object.values(registry).flatMap((entry) => {
    const cfg = config.landings.editorial?.[entry.slug];
    if (cfg?.published !== true) return [];
    if (base && entry.base !== base) return [];
    if (!editorialTranslatedLocales(cfg).includes(locale)) return [];
    return [
      {
        slug: entry.slug,
        title: text(pickLocalized(cfg.seo, "title", locale)) ?? entry.label,
        description: text(pickLocalized(cfg.seo, "description", locale)),
        href: editorialLandingHref(entry, locale),
        base: entry.base,
      },
    ];
  });
}

/** Una landing publicada tal como la necesita el sitemap. */
export interface EditorialSitemapEntry {
  slug: string;
  /** Ruta de la landing en el idioma por defecto (sin prefijo de idioma). */
  path: string;
  /** Ruta en cada idioma del sitio (sin prefijo): el tramo cambia con el idioma. */
  paths: Record<string, string>;
  /** Idiomas en los que existe de verdad: el resto no se anuncia. */
  locales: string[];
  /** `AAAA-MM-DD` de la última revisión que escribió el editor, o `null`. */
  lastModified: string | null;
}

/** Las landings publicadas con sus rutas por idioma. Las asociadas a un producto no entran. */
export function editorialSitemapEntries(
  config: Pick<SiteConfig, "landings" | "modules">,
): EditorialSitemapEntry[] {
  if (!config.landings || !isModuleEnabled(config, "landings")) return [];
  const registry = buildEditorialRegistry(
    config.landings.registry,
    config.landings.editorial,
  );
  return Object.values(registry).flatMap((entry) => {
    const cfg = config.landings.editorial?.[entry.slug];
    if (cfg?.published !== true) return [];
    return [
      {
        slug: entry.slug,
        path: editorialLandingHref(entry),
        paths: editorialLandingPaths(entry),
        locales: editorialTranslatedLocales(cfg),
        lastModified: isoDate(cfg.seo?.date_modified),
      },
    ];
  });
}
