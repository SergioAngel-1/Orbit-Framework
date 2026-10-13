import type { MetadataRoute } from "next";

// ============================================================================
//  Serialización del sitemap a XML.
//
//  La escribimos nosotros porque el sitemap no usa la convención
//  `app/sitemap.ts`: esa convención RESERVA `/sitemap.xml` aunque con
//  `generateSitemaps()` responda 404 ahí, y `/sitemap.xml` es justo la URL que
//  anuncia `robots.txt` y la que se da de alta en Search Console. Con route
//  handlers propios, el índice vive donde tiene que vivir y los segmentos en
//  `/sitemap/<segmento>.xml`.
//
//  El formato es el de la convención de Next: `xhtml:link` por idioma dentro de
//  cada `<url>` (incluido el x-default), `lastmod` en ISO y `changefreq` /
//  `priority` cuando los hay.
// ============================================================================

const URLSET_OPEN =
  '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';

/** Escapa lo que el XML no admite en texto ni en atributo. */
function esc(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&apos;");
}

function isoDate(value: Date | string | undefined): string | null {
  if (!value) return null;
  const date = value instanceof Date ? value : new Date(value);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/** `<urlset>` de un segmento. */
export function sitemapXml(entries: MetadataRoute.Sitemap): string {
  const urls = entries.map((entry) => {
    const lines = [`<loc>${esc(entry.url)}</loc>`];
    for (const [lang, href] of Object.entries(entry.alternates?.languages ?? {})) {
      if (typeof href === "string") {
        lines.push(
          `<xhtml:link rel="alternate" hreflang="${esc(lang)}" href="${esc(href)}" />`,
        );
      }
    }
    const lastmod = isoDate(entry.lastModified);
    if (lastmod) lines.push(`<lastmod>${lastmod}</lastmod>`);
    if (entry.changeFrequency) {
      lines.push(`<changefreq>${entry.changeFrequency}</changefreq>`);
    }
    if (entry.priority !== undefined)
      lines.push(`<priority>${entry.priority}</priority>`);
    return `<url>\n${lines.map((l) => `  ${l}`).join("\n")}\n</url>`;
  });
  return `<?xml version="1.0" encoding="UTF-8"?>\n${URLSET_OPEN}\n${urls.join("\n")}\n</urlset>\n`;
}

/** `<sitemapindex>` con un `<sitemap>` por segmento. */
export function sitemapIndexXml(locs: string[]): string {
  // Sin `<lastmod>` a propósito: el índice no conoce la fecha real de cada
  // segmento, y ponerle la de la petición diría «los tres cambiaron ahora»
  // cada vez que un crawler lo pide. El campo es opcional, y uno inventado
  // hace que el buscador deje de fiarse también del que sí es real.
  const items = locs.map(
    (loc) => `  <sitemap>\n    <loc>${esc(loc)}</loc>\n  </sitemap>`,
  );
  return (
    '<?xml version="1.0" encoding="UTF-8"?>\n' +
    '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' +
    `${items.join("\n")}\n` +
    "</sitemapindex>\n"
  );
}

/** Cabeceras comunes de los dos ficheros. */
export function xmlResponse(
  body: string,
  options: { cacheable?: boolean } = {},
): Response {
  const { cacheable = true } = options;
  return new Response(body, {
    headers: {
      "Content-Type": "application/xml; charset=utf-8",
      "Cache-Control": cacheable
        ? "public, max-age=0, s-maxage=300, stale-while-revalidate=86400"
        : "no-store",
    },
  });
}
