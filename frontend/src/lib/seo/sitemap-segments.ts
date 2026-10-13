// ============================================================================
//  Segmentos del sitemap.
//
//  Vive en `lib/` y no junto a la ruta porque lo consumen DOS: el índice
//  (`/sitemap.xml`) y cada fichero (`/sitemap/<segmento>.xml`). La lista tiene
//  que ser la misma en los dos sitios o el índice declara un fichero que no
//  existe.
//
//  El corte es por CICLO DE VIDA, no por tamaño: cada segmento lo mueve una
//  fuente distinta (las páginas del sitio, el catálogo de WooCommerce, las
//  entradas del blog), así que en Search Console la cobertura y los errores se
//  leen por tipo de contenido en vez de mezclados en un solo fichero.
// ============================================================================

export const SITEMAP_SEGMENTS = ["paginas", "catalogo", "blog"] as const;

export type SitemapSegment = (typeof SITEMAP_SEGMENTS)[number];
