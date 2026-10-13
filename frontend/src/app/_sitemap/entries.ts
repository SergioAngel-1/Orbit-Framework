import type { MetadataRoute } from "next";
import { getSiteConfig, isFallbackSiteConfig } from "@/lib/config";
import { routing } from "@/i18n/routing";
import { getCategories, getProductUrls } from "@/lib/catalog/products";
import { getPostSlugs } from "@/lib/blog/posts";
import { getBlogCategories } from "@/lib/blog/categories";
import { categoryPageAvailable } from "@/lib/blog/category-format";
import {
  getProductBoundLandingLocales,
  getProductBoundLandingSlugs,
} from "@/lib/landings/product-bound-landings";
import { productLearnPaths } from "@/lib/landings/product-learn-href";
import { editorialSitemapEntries } from "@/lib/landings/editorial-list";
import { entityPaths, hreflangLanguages, localizedPath } from "@/lib/seo/urls";
import type { SitemapSegment } from "@/lib/seo/sitemap-segments";
import { logger } from "@/lib/observability/logger";

// ============================================================================
//  Sitemap SEGMENTADO (índice + un fichero por tipo de contenido).
//
//  `/sitemap.xml` es el índice que declara los segmentos, y cada uno vive en
//  `/sitemap/<id>.xml`. El corte NO es por tamaño (un fichero único seguiría
//  siendo válido hasta 50.000 URLs) sino por CICLO DE VIDA:
//
//    · `paginas`  — estáticas y legales. Cambian cuando cambia el sitio.
//    · `catalogo` — fichas y categorías. Las mueve el webhook `product.*` de
//                   WooCommerce (tag `products`).
//    · `blog`     — las entradas. Las mueve el hook de entradas de WordPress
//                   (tag `posts`).
//
//  Lo que se gana: en Search Console la cobertura y los errores se leen POR
//  SEGMENTO —«faltan 12 fichas» deja de esconderse entre cientos de URLs—, y
//  un crawler que solo quiere ver si hay entradas nuevas descarga un fichero
//  pequeño. `robots.txt` anuncia `/sitemap.xml`, que es el índice.
// ============================================================================

interface SitemapPath {
  /** Ruta interna (`/products`, `/blog/mi-entrada`). */
  path: string;
  changeFrequency: MetadataRoute.Sitemap[number]["changeFrequency"];
  priority: number;
  lastModified?: Date;
  /**
   * Idiomas en los que la entidad existe de verdad (por defecto, todos). Una
   * entidad sin traducción solo emite su fila del idioma por defecto: la URL
   * `/en/...` serviría el mismo contenido y la propia página ya no anuncia ese
   * hreflang (ver `lib/seo/urls.ts`).
   */
  locales?: readonly string[];
  /**
   * Ruta de la entidad en cada idioma (sin prefijo de locale) cuando el tramo
   * de URL cambia con el idioma. Un idioma sin entrada usa `path`.
   */
  paths?: Readonly<Record<string, string>>;
}

/** Expande una ruta interna a una entrada por idioma, con alternates. */
function expand(baseUrl: string, entry: SitemapPath): MetadataRoute.Sitemap {
  const options = {
    ...(entry.locales ? { locales: entry.locales } : {}),
    ...(entry.paths ? { paths: entry.paths } : {}),
  };
  const languages = Object.fromEntries(
    Object.entries(hreflangLanguages(entry.path, options)).map(([lang, path]) => [
      lang,
      `${baseUrl}${path}`,
    ]),
  );
  const locales = routing.locales.filter(
    (locale) =>
      locale === routing.defaultLocale ||
      !entry.locales ||
      entry.locales.includes(locale),
  );
  return locales.map((locale) => ({
    url: `${baseUrl}${localizedPath(entry.paths?.[locale] ?? entry.path, locale)}`,
    // Sin fecha REAL no se emite `lastmod`. Es opcional en el protocolo, y un
    // `new Date()` en todas las filas decía «todo el sitio cambió ahora» en
    // cada petición — el patrón exacto por el que Google deja de fiarse del
    // campo y lo ignora en TODO el sitemap.
    ...(entry.lastModified ? { lastModified: entry.lastModified } : {}),
    changeFrequency: entry.changeFrequency,
    priority: entry.priority,
    alternates: { languages },
  }));
}

/** Slugs legales: la misma lista que sirve `/legal/[slug]`. */
const LEGAL_SLUGS = ["privacy", "cookies", "terms", "returns"] as const;

/**
 * Entradas de UN segmento del sitemap. Lo consumen los dos route handlers
 * (`/sitemap.xml` y `/sitemap/<segmento>.xml`).
 */
export async function buildSitemapEntries(
  segmento: SitemapSegment,
): Promise<MetadataRoute.Sitemap> {
  const config = await getSiteConfig();

  // Sin config real de WordPress, `brand.url` es el default de código: TODAS
  // las URLs saldrían con un dominio que no es el nuestro, y la ventana ISR las
  // serviría. Un urlset VACÍO es válido e inocuo (el crawler vuelve luego); uno
  // con el dominio equivocado ensucia Search Console y compite con las
  // canónicas reales. No se lanza un error a propósito: el build de CI corre
  // SIN pila WP. En cuanto WordPress responde, la revalidación repuebla el
  // fichero.
  if (isFallbackSiteConfig(config)) {
    logger.error(
      { event: "sitemap.degraded_config" },
      "sitemap: config del sitio degradada; se emite vacío en vez de URLs con el dominio por defecto",
    );
    return [];
  }

  const baseUrl = config.brand.url.replace(/\/$/, "");
  const paths: SitemapPath[] = [];

  if (segmento === "paginas") {
    paths.push(
      { path: "/", changeFrequency: "daily", priority: 1 },
      { path: "/products", changeFrequency: "weekly", priority: 0.8 },
      { path: "/blog", changeFrequency: "weekly", priority: 0.6 },
      { path: "/about", changeFrequency: "monthly", priority: 0.5 },
      { path: "/contact", changeFrequency: "monthly", priority: 0.4 },
    );
    for (const slug of LEGAL_SLUGS) {
      paths.push({ path: `/legal/${slug}`, changeFrequency: "yearly", priority: 0.3 });
    }
    // Landings editoriales (módulo `landings`; vacío si está apagado): cada una solo en los
    // idiomas donde existe, con el tramo de cada uno. `lastmod` solo con la fecha de revisión
    // que escribió el editor: nunca una inventada.
    const landings = editorialSitemapEntries(config);
    if (landings.length > 0) {
      paths.push({ path: "/learn", changeFrequency: "weekly", priority: 0.5 });
    }
    for (const landing of landings) {
      paths.push({
        path: landing.path,
        locales: landing.locales,
        paths: landing.paths,
        changeFrequency: "monthly",
        priority: 0.6,
        ...(landing.lastModified
          ? { lastModified: new Date(landing.lastModified) }
          : {}),
      });
    }
  }

  if (segmento === "catalogo") {
    // Fail-soft deliberado: un sitemap sin productos es mejor que una ruta
    // caída, pero el fallo queda visible en los logs.
    try {
      // Landings de producto publicadas (módulos `product-landing` y `landings`;
      // vacío si están apagados). Cada una solo se anuncia en los idiomas en que
      // la ficha Y la landing existen.
      const learn = await getProductBoundLandingSlugs();
      const learnLocales = learn.size > 0 ? await getProductBoundLandingLocales() : {};
      for (const { slug, slugs, locales } of await getProductUrls()) {
        paths.push({
          path: `/products/${slug}`,
          locales,
          paths: entityPaths("/products", slugs),
          changeFrequency: "weekly",
          priority: 0.7,
        });
        if (learn.has(slug)) {
          const translated = learnLocales[slug] ?? [routing.defaultLocale];
          paths.push({
            path: `/products/${slug}/learn`,
            locales: locales.filter((locale) => translated.includes(locale)),
            paths: productLearnPaths(slugs),
            changeFrequency: "monthly",
            priority: 0.5,
          });
        }
      }
    } catch (error) {
      logger.error(
        { err: error },
        "sitemap: no se pudo listar el catálogo de productos",
      );
    }
    try {
      for (const cat of await getCategories(routing.defaultLocale, {
        allLocales: true,
      })) {
        paths.push({
          path: `/categories/${cat.slug}`,
          ...(cat.locales ? { locales: cat.locales } : {}),
          paths: entityPaths("/categories", cat.urlSlugs),
          changeFrequency: "weekly",
          priority: 0.6,
        });
      }
    } catch (error) {
      logger.error({ err: error }, "sitemap: no se pudo listar las categorías");
    }
  }

  if (segmento === "blog") {
    // El archivo (lista de enlaces a TODAS las entradas) y las categorías con
    // página propia viven con el blog porque las mueve el mismo tag `posts`.
    paths.push({ path: "/blog/archive", changeFrequency: "weekly", priority: 0.4 });
    try {
      for (const category of await getBlogCategories()) {
        // Mismo criterio que la página: lo que ella no sirve, el sitemap no lo anuncia.
        if (!categoryPageAvailable(category)) continue;
        paths.push({
          path: `/blog/category/${category.slug}`,
          locales: category.locales,
          changeFrequency: "weekly",
          priority: 0.5,
        });
      }
    } catch (error) {
      // Fail-soft: un CMS caído no debe tumbar el segmento (las entradas y el
      // archivo siguen saliendo).
      logger.error(
        { err: error },
        "sitemap: no se pudo listar las categorías del blog",
      );
    }
    try {
      for (const { slug, modified, slugs, locales } of await getPostSlugs()) {
        paths.push({
          path: `/blog/${slug}`,
          locales,
          paths: entityPaths("/blog", slugs),
          changeFrequency: "monthly",
          priority: 0.6,
          lastModified: modified ? new Date(modified) : undefined,
        });
      }
    } catch (error) {
      logger.error({ err: error }, "sitemap: no se pudo listar las entradas del blog");
    }
  }

  return paths.flatMap((entry) => expand(baseUrl, entry));
}
