import { isEntityAnnounced, type EntityIndexControls } from "@/lib/seo/entity-index";
import {
  isSitemapPathExcluded,
  resolveInstanceSitemap,
  type InstanceSitemapRules,
} from "@/lib/instance/sitemap";

// ============================================================================
//  ¿Se anuncia esta entidad? Un único criterio para sitemap, `llms.txt` y los
//  índices HTML (A-Z del catálogo): la caja SEO de la entidad (`noindex` o
//  canónica ajena) Y las exclusiones de la instancia (`InstanceRegistry.sitemap.
//  exclude`, exactas o con `/*`) comparadas contra la ruta INTERNA.
//
//  Vive en `lib/` para que una instancia que reescribe una vista solo tenga que
//  llamar a esta función en vez de repetir las dos comprobaciones.
// ============================================================================

/**
 * @param path     Ruta INTERNA de la entidad, sin prefijo de idioma
 *                 (`/products/<slug-nativo>`, `/categories/<slug-nativo>`).
 * @param controls Caja SEO de la entidad (`seoNoindex`, `seoCanonical`).
 * @param rules    Reglas resueltas del registro; por defecto, las de la instancia.
 */
export function isPathAnnounced(
  path: string,
  controls: EntityIndexControls = {},
  rules: InstanceSitemapRules = resolveInstanceSitemap(),
): boolean {
  return isEntityAnnounced(controls) && !isSitemapPathExcluded(path, rules);
}
