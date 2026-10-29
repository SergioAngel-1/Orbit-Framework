import type { Metadata } from "next";
import { Link } from "@/i18n/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import {
  getCategories,
  getProductUrls,
  type ProductUrlEntry,
} from "@/lib/catalog/products";
import { categoryHref, productHref } from "@/lib/catalog/product-href";
import { groupProductsByInitial } from "@/lib/catalog/archive-format";
import { getSiteConfig } from "@/lib/config";
import { pageMetadata } from "@/lib/seo/page-metadata";
import { isEntityAnnounced } from "@/lib/seo/entity-index";
import { buildPageBreadcrumbJsonLd } from "@/lib/seo/breadcrumbs";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";

export const revalidate = 300;

// ============================================================================
//  /products/archive — TODAS las fichas, de la A a la Z, en HTML estático.
//  El scroll infinito de /products y el tope de 24 de cada categoría no los
//  recorre un rastreador: sin esta página, una ficha más allá de la primera
//  tanda solo existía en el sitemap, sin un enlace interno que la sostuviera.
// ============================================================================

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "products" }),
    getSiteConfig(),
  ]);
  return pageMetadata({
    config,
    locale,
    path: "/products/archive",
    title: t("archiveTitle"),
    description: t("archiveDescription"),
  });
}

export default async function ProductsArchivePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, tNav, config] = await Promise.all([
    getTranslations("products"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);

  // Un fallo del catálogo NO se traga: se avisa, en vez de servir un índice
  // vacío que ISR cachearía como si fuera el bueno.
  let entries: ProductUrlEntry[] = [];
  let failed = false;
  try {
    entries = await getProductUrls({ all: true, strict: true });
  } catch {
    failed = true;
  }
  const categories = failed
    ? []
    : (await getCategories(locale).catch(() => [])).filter(isEntityAnnounced);
  // Mismo criterio que el sitemap: ni fichas ocultas ni las que no existen en
  // este idioma (servirían el texto de otro).
  const groups = groupProductsByInitial(
    entries.filter(
      (entry) =>
        !entry.hidden && isEntityAnnounced(entry) && entry.locales.includes(locale),
    ),
    locale,
  );

  const breadcrumbJsonLd = buildPageBreadcrumbJsonLd(config, locale, tNav("home"), [
    { name: t("title"), href: "/products" },
    { name: t("archiveTitle"), href: "/products/archive" },
  ]);

  return (
    <div>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbJsonLd) }}
      />
      <nav className="mb-6 text-sm text-gray-500 dark:text-gray-400">
        <Link
          href="/products"
          className="transition-colors hover:text-brand dark:hover:text-accent"
        >
          {t("backToStore")}
        </Link>
      </nav>
      <h1 className="mb-2 text-3xl font-extrabold tracking-tight">
        {t("archiveTitle")}
      </h1>
      <p className="mb-8 max-w-2xl text-gray-600 dark:text-gray-300">
        {failed ? t("archiveError") : t("archiveDescription")}
      </p>

      {categories.length > 0 && (
        <section className="mb-10">
          <h2 className="mb-3 text-xl font-bold tracking-tight">
            {t("archiveCategories")}
          </h2>
          <ul className="flex flex-wrap gap-x-6 gap-y-2">
            {categories.map((category) => (
              <li key={category.slug}>
                <Link
                  href={categoryHref(category)}
                  className="font-medium transition-colors hover:text-brand dark:hover:text-accent"
                >
                  {category.name}
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      {!failed && groups.length === 0 && (
        <p className="text-gray-600 dark:text-gray-300">{t("archiveEmpty")}</p>
      )}

      <div className="space-y-8">
        {groups.map((group) => (
          <section key={group.initial}>
            <h2 className="mb-3 text-xl font-bold tracking-tight">{group.initial}</h2>
            <ul className="divide-y divide-gray-200 dark:divide-gray-800">
              {group.products.map((product) => (
                <li key={product.slug} className="py-2">
                  <Link
                    href={productHref(product)}
                    className="font-medium transition-colors hover:text-brand dark:hover:text-accent"
                  >
                    {product.name}
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </div>
  );
}
