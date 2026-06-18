import type { MetadataRoute } from "next";
import { siteConfig } from "@/config/site";
import { routing } from "@/i18n/routing";
import { getProductSlugs } from "@/lib/catalog/products";

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

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  // Solo páginas indexables (se excluyen carrito/checkout/login/cuenta).
  const staticPages = ["", "/products"];

  const entries: MetadataRoute.Sitemap = [];

  for (const page of staticPages) {
    const languages = alternatesFor(page);
    for (const locale of routing.locales) {
      entries.push({
        url: localizedUrl(page, locale),
        lastModified: new Date(),
        changeFrequency: page === "" ? "daily" : "weekly",
        priority: page === "" ? 1 : 0.8,
        alternates: { languages },
      });
    }
  }

  let productSlugs: string[] = [];
  try {
    productSlugs = await getProductSlugs();
  } catch {
    /* sin productos todavía */
  }

  for (const slug of productSlugs) {
    const path = `/products/${slug}`;
    const languages = alternatesFor(path);
    for (const locale of routing.locales) {
      entries.push({
        url: localizedUrl(path, locale),
        lastModified: new Date(),
        changeFrequency: "weekly",
        priority: 0.7,
        alternates: { languages },
      });
    }
  }

  return entries;
}
