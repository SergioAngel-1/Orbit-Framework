import { buildSitemapEntries } from "@/app/_sitemap/entries";
import { sitemapXml, xmlResponse } from "@/app/_sitemap/xml";
import { SITEMAP_SEGMENTS, type SitemapSegment } from "@/lib/seo/sitemap-segments";

// ============================================================================
//  /sitemap/<segmento>.xml — un fichero por tipo de contenido.
//
//  El parámetro llega con la extensión (`catalogo.xml`) para que la URL sea la
//  que un buscador espera de un sitemap. Un segmento que no esté declarado en
//  `SITEMAP_SEGMENTS` es 404, no un `<urlset>` vacío: un fichero válido y
//  vacío en una URL inventada es exactamente lo que hace que nadie se entere
//  de que el índice apunta a donde no debe.
// ============================================================================

export const runtime = "nodejs";
export const revalidate = 3600;

export async function generateStaticParams(): Promise<{ segmento: string }[]> {
  return SITEMAP_SEGMENTS.map((segmento) => ({ segmento: `${segmento}.xml` }));
}

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ segmento: string }> },
): Promise<Response> {
  const { segmento } = await params;
  const nombre = segmento.endsWith(".xml") ? segmento.slice(0, -4) : segmento;
  if (!SITEMAP_SEGMENTS.includes(nombre as SitemapSegment)) {
    return new Response("Not Found", { status: 404 });
  }
  return xmlResponse(sitemapXml(await buildSitemapEntries(nombre as SitemapSegment)));
}
