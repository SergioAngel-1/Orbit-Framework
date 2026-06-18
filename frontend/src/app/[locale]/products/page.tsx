import { Link } from "@/i18n/navigation";
import { getTranslations } from "next-intl/server";
import { getProducts } from "@/lib/catalog/products";
import { ProductGrid } from "@/components/products/product-grid";

export const revalidate = 300;

export default async function ProductsPage({
  searchParams,
}: {
  searchParams: Promise<{ search?: string; after?: string }>;
}) {
  const t = await getTranslations("products");
  const { search, after } = await searchParams;

  let products: Awaited<ReturnType<typeof getProducts>>["products"] = [];
  let pageInfo = { hasNextPage: false, endCursor: null as string | null };
  let errorMessage: string | null = null;

  try {
    const result = await getProducts({ search, after, first: 12 });
    products = result.products;
    pageInfo = result.pageInfo;
  } catch (e) {
    errorMessage = e instanceof Error ? e.message : t("error");
  }

  const nextHref = pageInfo.endCursor
    ? `/products?${new URLSearchParams({
        ...(search ? { search } : {}),
        after: pageInfo.endCursor,
      }).toString()}`
    : null;

  return (
    <div>
      <h1 className="mb-6 text-3xl font-extrabold tracking-tight">{t("title")}</h1>

      <form action="/products" method="get" className="mb-8 flex gap-2">
        <input
          type="search"
          name="search"
          defaultValue={search ?? ""}
          placeholder={t("searchPlaceholder")}
          className="w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
        <button
          type="submit"
          className="rounded-lg bg-brand px-4 py-2 font-semibold text-white hover:bg-brand-dark"
        >
          {t("searchButton")}
        </button>
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
