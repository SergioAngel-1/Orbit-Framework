import Link from "next/link";
import type { Metadata } from "next";
import { getProducts } from "@/lib/catalog/products";
import { ProductGrid } from "@/components/products/product-grid";

export const revalidate = 300;

export const metadata: Metadata = {
  title: "Tienda",
  description: "Catálogo de productos.",
};

export default async function ProductsPage({
  searchParams,
}: {
  searchParams: Promise<{ search?: string; after?: string }>;
}) {
  const { search, after } = await searchParams;

  let products: Awaited<ReturnType<typeof getProducts>>["products"] = [];
  let pageInfo = { hasNextPage: false, endCursor: null as string | null };
  let errorMessage: string | null = null;

  try {
    const result = await getProducts({ search, after, first: 12 });
    products = result.products;
    pageInfo = result.pageInfo;
  } catch (e) {
    errorMessage =
      e instanceof Error ? e.message : "No se pudo cargar el catálogo.";
  }

  const nextHref = pageInfo.endCursor
    ? `/products?${new URLSearchParams({
        ...(search ? { search } : {}),
        after: pageInfo.endCursor,
      }).toString()}`
    : null;

  return (
    <div>
      <h1 className="mb-6 text-3xl font-extrabold tracking-tight">Tienda</h1>

      <form action="/products" method="get" className="mb-8 flex gap-2">
        <input
          type="search"
          name="search"
          defaultValue={search ?? ""}
          placeholder="Buscar productos…"
          className="w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
        <button
          type="submit"
          className="rounded-lg bg-brand px-4 py-2 font-semibold text-white hover:bg-brand-dark"
        >
          Buscar
        </button>
      </form>

      {errorMessage ? (
        <div className="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          <p className="font-semibold">No se pudo cargar el catálogo.</p>
          <p className="mt-1 text-sm opacity-80">{errorMessage}</p>
          <p className="mt-2 text-sm">
            Comprueba que WooCommerce y WooGraphQL están activos.
          </p>
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
                Cargar más →
              </Link>
            </div>
          )}
        </>
      )}
    </div>
  );
}
