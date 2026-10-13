import { HUBSPOT_DO_NOT_COLLECT } from "@/lib/analytics/hubspot";
import type { Metadata } from "next";
import { Link } from "@/i18n/navigation";
import { getLocale, getTranslations } from "next-intl/server";
import { getProducts, getCategories } from "@/lib/catalog/products";
import { absoluteLocalized, alternatesFor } from "@/lib/seo/urls";
import { getSiteConfig } from "@/lib/config";
import {
  COLLECTION_ITEM_LIST_LIMIT,
  buildCollectionPageJsonLd,
} from "@/lib/seo/jsonld";
import { buildPageBreadcrumbJsonLd } from "@/lib/seo/breadcrumbs";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { DERIVED_LISTING_ROBOTS, hasListingState } from "@/lib/seo/listing";
import { siteOpenGraph, siteTwitter } from "@/lib/seo/opengraph";
import { viewSocialImages } from "@/lib/seo/og-static";
import { siteTitle } from "@/lib/seo/title";
import { metaDescription } from "@/lib/seo/meta-description";
import { InfiniteProductGrid } from "@/components/products/infinite-product-grid";

export const revalidate = 300;

/** Parámetros que cambian lo que lista la página (el resto, p. ej. `utm_*`, no). */
const LISTING_PARAMS = [
  "search",
  "after",
  "category",
  "minPrice",
  "maxPrice",
  "sort",
] as const;

export async function generateMetadata({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}): Promise<Metadata> {
  const { locale } = await params;
  const [t, config, query] = await Promise.all([
    getTranslations({ locale, namespace: "products" }),
    getSiteConfig(),
    searchParams,
  ]);
  const title = siteTitle(config, t("title"));

  // Una vista filtrada, ordenada o paginada es derivada: `noindex, follow` y
  // SIN `alternates` (ver `lib/seo/listing.ts`). Solo el listado base compite.
  if (hasListingState(query, LISTING_PARAMS)) {
    return { title, robots: DERIVED_LISTING_ROBOTS };
  }

  const canonical = alternatesFor("/products", locale);
  const description = metaDescription(config.brand.description) || undefined;
  const cards = viewSocialImages("products", locale, t("title"));
  return {
    title,
    ...(description ? { description } : {}),
    alternates: canonical,
    openGraph: {
      ...siteOpenGraph(config, locale, canonical.canonical),
      ...cards.openGraph,
    },
    twitter: { ...siteTwitter(config, locale), ...cards.twitter },
  };
}

export default async function ProductsPage({
  searchParams,
}: {
  searchParams: Promise<{
    search?: string;
    after?: string;
    category?: string;
    minPrice?: string;
    maxPrice?: string;
    sort?: string;
  }>;
}) {
  const [t, tNav, config] = await Promise.all([
    getTranslations("products"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);
  const { search, after, category, minPrice, maxPrice, sort } = await searchParams;

  const filters = { search, category, minPrice, maxPrice, sort };

  let products: Awaited<ReturnType<typeof getProducts>>["products"] = [];
  let pageInfo = { hasNextPage: false, endCursor: null as string | null };
  let errorMessage: string | null = null;

  try {
    const result = await getProducts({
      search,
      after,
      category,
      minPrice,
      maxPrice,
      sort,
      first: 12,
    });
    products = result.products;
    pageInfo = result.pageInfo;
  } catch (e) {
    errorMessage = e instanceof Error ? e.message : t("error");
  }

  const categories = await getCategories();

  // CollectionPage + breadcrumb solo en la vista base: las derivadas no se
  // indexan, y un `ItemList` de resultados de búsqueda no es la colección.
  const isBaseView = !hasListingState(
    { search, after, category, minPrice, maxPrice, sort },
    LISTING_PARAMS,
  );
  const locale = await getLocale();
  const collectionJsonLd =
    isBaseView && products.length > 0
      ? buildCollectionPageJsonLd({
          name: t("title"),
          url: absoluteLocalized(config.brand.url, "/products", locale),
          items: products.slice(0, COLLECTION_ITEM_LIST_LIMIT).map((product) => ({
            name: product.name,
            url: absoluteLocalized(
              config.brand.url,
              `/products/${product.slug}`,
              locale,
            ),
            image: product.image?.sourceUrl ?? null,
          })),
        })
      : null;
  const breadcrumbJsonLd = isBaseView
    ? buildPageBreadcrumbJsonLd(config, locale, tNav("home"), [
        { name: t("title"), href: "/products" },
      ])
    : null;

  return (
    <div>
      {collectionJsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd(collectionJsonLd) }}
        />
      )}
      {breadcrumbJsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd(breadcrumbJsonLd) }}
        />
      )}
      <h1 className="mb-6 text-3xl font-extrabold tracking-tight">{t("title")}</h1>

      <form
        {...HUBSPOT_DO_NOT_COLLECT}
        action="/products"
        method="get"
        className="mb-8 flex flex-wrap items-end gap-3"
      >
        <div>
          <label
            htmlFor="search"
            className="mb-1 block text-xs font-medium text-gray-500"
          >
            {t("searchPlaceholder")}
          </label>
          <input
            id="search"
            type="search"
            name="search"
            defaultValue={search ?? ""}
            placeholder={t("searchPlaceholder")}
            className="w-full max-w-xs rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
          />
        </div>

        <div>
          <label
            htmlFor="sort"
            className="mb-1 block text-xs font-medium text-gray-500"
          >
            {t("sortBy")}
          </label>
          <select
            id="sort"
            name="sort"
            defaultValue={sort ?? ""}
            className="rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
          >
            <option value="">—</option>
            <option value="date-desc">{t("sortNewest")}</option>
            <option value="price-asc">{t("sortPriceAsc")}</option>
            <option value="price-desc">{t("sortPriceDesc")}</option>
            <option value="title-asc">{t("sortNameAsc")}</option>
          </select>
        </div>

        <div>
          <label
            htmlFor="category"
            className="mb-1 block text-xs font-medium text-gray-500"
          >
            {t("filter")}
          </label>
          <select
            id="category"
            name="category"
            defaultValue={category ?? ""}
            className="rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
          >
            <option value="">{t("allCategories")}</option>
            {categories.map((cat) => (
              <option key={cat.slug} value={cat.slug}>
                {cat.name}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label
            htmlFor="minPrice"
            className="mb-1 block text-xs font-medium text-gray-500"
          >
            {t("minPrice")}
          </label>
          <input
            id="minPrice"
            type="number"
            name="minPrice"
            min="0"
            step="0.01"
            defaultValue={minPrice ?? ""}
            placeholder="0"
            className="w-24 rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
          />
        </div>

        <div>
          <label
            htmlFor="maxPrice"
            className="mb-1 block text-xs font-medium text-gray-500"
          >
            {t("maxPrice")}
          </label>
          <input
            id="maxPrice"
            type="number"
            name="maxPrice"
            min="0"
            step="0.01"
            defaultValue={maxPrice ?? ""}
            placeholder="999"
            className="w-24 rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
          />
        </div>

        <button
          type="submit"
          className="rounded-lg bg-brand px-4 py-2 font-semibold text-white hover:bg-brand-dark"
        >
          {t("searchButton")}
        </button>

        {(search || category || minPrice || maxPrice || sort) && (
          <Link
            href="/products"
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium transition-colors hover:border-red-400 hover:text-red-600 dark:border-gray-700"
          >
            {t("clearFilters")}
          </Link>
        )}
      </form>

      {errorMessage ? (
        <div className="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          <p className="font-semibold">{t("error")}</p>
          <p className="mt-1 text-sm opacity-80">{errorMessage}</p>
          <p className="mt-2 text-sm">{t("errorHint")}</p>
        </div>
      ) : (
        <InfiniteProductGrid
          initialProducts={products}
          initialPageInfo={pageInfo}
          filters={filters}
          wishlistEnabled={config.ecommerce.wishlist_enabled}
        />
      )}
    </div>
  );
}
