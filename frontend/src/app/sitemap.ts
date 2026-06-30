import type { MetadataRoute } from "next";
import { siteConfig } from "@/config/site";
import { routing } from "@/i18n/routing";
import { getProductSlugs, getCategories } from "@/lib/catalog/products";
import { getPostSlugs } from "@/lib/blog/posts";

const BASE_URL = siteConfig.url;

/**
 * URL canónica por locale con `localePrefix: "as-needed"`:
 * el locale por defecto (es) NO lleva prefijo; el resto sí (/en/...).
 */
function localizedUrl(path: string, locale: string): string {
  const prefix = locale === routing.defaultLocale ? "" : `/${locale}`;
  const url = `${BASE_URL}${prefix}${path}`;
  return url === BASE_URL ? `${BASE_URL}/` : url;
}

/** hreflang alternates (incluye x-default apuntando al locale por defecto). */
function alternatesFor(path: string): Record<string, string> {
  const languages: Record<string, string> = {};
  for (const locale of routing.locales) {
    languages[locale] = localizedUrl(path, locale);
  }
  languages["x-default"] = localizedUrl(path, routing.defaultLocale);
  return languages;
}

interface SitemapPath {
  path: string;
  changeFrequency: MetadataRoute.Sitemap[number]["changeFrequency"];
  priority: number;
  lastModified?: Date;
}

/** Expande una ruta a una entrada por locale, con alternates compartidos. */
function expand(entry: SitemapPath): MetadataRoute.Sitemap {
  const languages = alternatesFor(entry.path);
  return routing.locales.map((locale) => ({
    url: localizedUrl(entry.path, locale),
    lastModified: entry.lastModified ?? new Date(),
    changeFrequency: entry.changeFrequency,
    priority: entry.priority,
    alternates: { languages },
  }));
}

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  // Páginas estáticas indexables (se excluyen carrito/checkout/login/cuenta).
  const paths: SitemapPath[] = [
    { path: "", changeFrequency: "daily", priority: 1 },
    { path: "/products", changeFrequency: "weekly", priority: 0.8 },
    { path: "/blog", changeFrequency: "weekly", priority: 0.6 },
    { path: "/about", changeFrequency: "monthly", priority: 0.5 },
  ];

  // Catálogo: fichas de producto.
  try {
    for (const slug of await getProductSlugs()) {
      paths.push({ path: `/products/${slug}`, changeFrequency: "weekly", priority: 0.7 });
    }
  } catch {
    /* sin productos todavía */
  }

  // Categorías.
  try {
    for (const cat of await getCategories()) {
      paths.push({ path: `/categories/${cat.slug}`, changeFrequency: "weekly", priority: 0.6 });
    }
  } catch {
    /* sin categorías todavía */
  }

  // Blog: entradas (con su fecha real de modificación).
  try {
    for (const { slug, modified } of await getPostSlugs()) {
      paths.push({
        path: `/blog/${slug}`,
        changeFrequency: "monthly",
        priority: 0.6,
        lastModified: modified ? new Date(modified) : undefined,
      });
    }
  } catch {
    /* sin posts todavía */
  }

  return paths.flatMap(expand);
}
