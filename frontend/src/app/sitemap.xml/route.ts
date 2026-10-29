import { getSiteConfig, isFallbackSiteConfig } from "@/lib/config";
import { SITEMAP_SEGMENTS } from "@/lib/seo/sitemap-segments";
import { sitemapIndexXml, xmlResponse } from "@/app/_sitemap/xml";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  /sitemap.xml — ÍNDICE de los sitemaps segmentados.
//
//  Es la URL que anuncia `robots.txt` y la que se da de alta en Search
//  Console; los ficheros reales viven en `/sitemap/<segmento>.xml`.
// ============================================================================

export const runtime = "nodejs";
// Dinámico a propósito: un ISR se prerenderiza en el build de la imagen, que corre SIN
// WordPress, y servía el resultado degradado (vacío / 404) hasta una hora tras cada
// despliegue. Los datos van por la Data Cache con sus tags y el CDN cachea la respuesta
// (`s-maxage`), así que pedirlo en cada petición es barato.
export const dynamic = "force-dynamic";

export async function GET(): Promise<Response> {
  const config = await getSiteConfig();

  // Mismo criterio que los segmentos y que robots.txt: con la config
  // degradada, `brand.url` es el default de código y el índice mandaría al
  // crawler a un dominio que no es el nuestro. Un índice vacío es válido.
  if (isFallbackSiteConfig(config)) {
    logger.error(
      { event: "sitemap_index.degraded_config" },
      "sitemap index: config del sitio degradada; se emite vacío",
    );
    return xmlResponse(sitemapIndexXml([]), { cacheable: false });
  }

  const base = config.brand.url.replace(/\/$/, "");
  return xmlResponse(
    sitemapIndexXml(SITEMAP_SEGMENTS.map((s) => `${base}/sitemap/${s}.xml`)),
  );
}
