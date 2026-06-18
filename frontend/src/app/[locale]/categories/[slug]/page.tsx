import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations } from "next-intl/server";
import { getCategory } from "@/lib/catalog/products";
import { ProductGrid } from "@/components/products/product-grid";
import { stripHtml } from "@/lib/format";

export const revalidate = 300;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const t = await getTranslations("products");
  const { slug } = await params;
  try {
    const result = await getCategory(slug);
    if (!result) return { title: t("notFound") };
    return {
      title: result.category.name,
      description: stripHtml(result.category.description).slice(0, 160),
    };
  } catch {
    return { title: t("title") };
  }
}

export default async function CategoryPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;

  let result: Awaited<ReturnType<typeof getCategory>>;
  try {
    result = await getCategory(slug);
  } catch {
    notFound();
  }
  if (!result) notFound();

  return (
    <div>
      <h1 className="mb-2 text-3xl font-extrabold tracking-tight">
        {result.category.name}
      </h1>
      {result.category.description && (
        <p className="mb-8 max-w-2xl text-gray-600 dark:text-gray-300">
          {stripHtml(result.category.description)}
        </p>
      )}
      <ProductGrid products={result.products} />
    </div>
  );
}
