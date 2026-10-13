import { describe, it, expect } from "vitest";
import { sitemapXml, sitemapIndexXml } from "./xml";

// ============================================================================
//  El XML lo serializamos nosotros desde que el sitemap dejó la convención
//  `app/sitemap.ts` (reservaba `/sitemap.xml` para servir un 404 ahí). Esto
//  fija el formato que emitía Next, que es lo que ya está indexado.
// ============================================================================

describe("sitemapXml", () => {
  const entry = {
    url: "https://mitienda.example/products",
    changeFrequency: "weekly" as const,
    priority: 0.8,
    alternates: {
      languages: {
        es: "https://mitienda.example/products",
        en: "https://mitienda.example/en/products",
        "x-default": "https://mitienda.example/products",
      },
    },
  };

  it("declara el namespace xhtml y una alternate por idioma", () => {
    const xml = sitemapXml([entry]);
    expect(xml).toContain('xmlns:xhtml="http://www.w3.org/1999/xhtml"');
    expect(xml).toContain(
      '<xhtml:link rel="alternate" hreflang="en" href="https://mitienda.example/en/products" />',
    );
    expect(xml).toContain(
      '<xhtml:link rel="alternate" hreflang="x-default" href="https://mitienda.example/products" />',
    );
    expect(xml.match(/<xhtml:link/g)).toHaveLength(3);
  });

  it("no emite `lastmod` cuando no hay fecha real", () => {
    expect(sitemapXml([entry])).not.toContain("<lastmod>");
  });

  it("emite `lastmod` en ISO cuando la hay", () => {
    const xml = sitemapXml([
      { ...entry, lastModified: new Date("2026-09-20T10:00:00Z") },
    ]);
    expect(xml).toContain("<lastmod>2026-09-20T10:00:00.000Z</lastmod>");
  });

  it("escapa lo que el XML no admite", () => {
    const xml = sitemapXml([{ ...entry, url: "https://mitienda.example/x?a=1&b=2" }]);
    expect(xml).toContain("<loc>https://mitienda.example/x?a=1&amp;b=2</loc>");
    expect(xml).not.toContain("a=1&b=2");
  });

  it("un segmento sin entradas sigue siendo un urlset válido", () => {
    const xml = sitemapXml([]);
    expect(xml).toContain("<urlset");
    expect(xml).toContain("</urlset>");
    expect(xml).not.toContain("<url>");
  });
});

describe("sitemapIndexXml", () => {
  it("lista un <sitemap> por segmento y ningún lastmod inventado", () => {
    const xml = sitemapIndexXml([
      "https://mitienda.example/sitemap/paginas.xml",
      "https://mitienda.example/sitemap/catalogo.xml",
    ]);
    expect(xml).toContain("<sitemapindex");
    expect(xml.match(/<sitemap>/g)).toHaveLength(2);
    expect(xml).toContain("<loc>https://mitienda.example/sitemap/catalogo.xml</loc>");
    expect(xml).not.toContain("<lastmod>");
  });
});
