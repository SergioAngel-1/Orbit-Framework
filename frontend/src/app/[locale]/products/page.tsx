import { Link } from "@/i18n/navigation";
import { getTranslations } from "next-intl/server";
import { getProducts, getCategories } from "@/lib/catalog/products";
import { ProductGrid } from "@/components/products/product-grid";

export const revalidate = 300;

export default async function ProductsPage({
  searchParams,
}: {
  searchParams: Promise<{ search?: string; after?: string; category?: string; minPrice?: string; maxPrice?: string; sort?: string }>;
}) {
  const t = await getTranslations("products");
  const { search, after, category, minPrice, maxPrice, sort } = await searchParams;

  let products: Awaited<ReturnType<typeof getProducts>>["products"] = [];
  let pageInfo = { hasNextPage: false, endCursor: null as string | null };
  let errorMessage: string | null = null;

  try {
    const result = await getProducts({ search, after, category, minPrice, maxPrice, sort, first: 12 });
    products = result.products;
    pageInfo = result.pageInfo;
  } catch (e) {
    errorMessage = e instanceof Error ? e.message : t("error");
  }

  const categories = await getCategories();

  const nextHref = pageInfo.endCursor
    ? `/products?${new URLSearchParams({
        ...(search ? { search } : {}),
        ...(category ? { category } : {}),
        ...(minPrice ? { minPrice } : {}),
        ...(maxPrice ? { maxPrice } : {}),
        ...(sort ? { sort } : {}),
        after: pageInfo.endCursor,
      }).toString()}`
    : null;

  return (
    <div>
      <h1 className="mb-6 text-3xl font-extrabold tracking-tight">{t("title")}</h1>

      <form action="/products" method="get" className="mb-8 flex flex-wrap items-end gap-3">
        <div>
          <label className="mb-1 block text-xs font-medium text-gray-500">{t("searchPlaceholder")}</label>
          <input
            type="search"
            name="search"
            defaultValue={search ?? ""}
            placeholder={t("searchPlaceholder")}
            className="w-full max-w-xs rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
          />
        </div>

        <div>
          <label className="mb-1 block text-xs font-medium text-gray-500">{t("sortBy")}</label>
          <select
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
          <label className="mb-1 block text-xs font-medium text-gray-500">{t("filter")}</label>
          <select
            name="category"
            defaultValue={category ?? ""}
            className="rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
          >
            <option value="">{t("allCategories")}</option>
            {categories.map((cat) => (
              <option key={cat.slug} value={cat.slug}>{cat.name}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="mb-1 block text-xs font-medium text-gray-500">{t("minPrice")}</label>
          <input
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
          <label className="mb-1 block text-xs font-medium text-gray-500">{t("maxPrice")}</label>
          <input
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
        <>
          <ProductGrid products={products} />
          {nextHref && (
            <div className="mt-10 text-center">
              <Link
                href={nextHref}
                className="inline-block rounded-lg border border-gray-300 px-6 py-2 font-medium transition-colors hover:border-brand hover:text-brand dark:border-gray-700"
              >
                {t("loadMore")}
              </Link>
            </div>
          )}
        </>
      )}
    </div>
  );
}
