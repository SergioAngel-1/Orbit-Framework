import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { CmsImage } from "@/components/ui/cms-image";
import { Link } from "@/i18n/navigation";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { publishedEditorialLandings } from "@/lib/landings/editorial-list";
import { groupLearnProducts } from "@/lib/landings/learn-index";
import { getLearnIndexProducts } from "@/lib/landings/learn-index-products";
import { pageMetadata } from "@/lib/seo/page-metadata";
import { instanceRegistry } from "@/instance/registry";

// ============================================================================
//  Índice del área educativa — `/learn`.
//
//  Reúne las landings editoriales publicadas del área (módulo `landings`) y las
//  guías de producto, agrupadas por la categoría del producto. Con el módulo
//  `landings` apagado no hay área: 404 (las guías de producto viven en la ficha).
// ============================================================================

export const revalidate = 300;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "landings" }),
    getSiteConfig(),
  ]);
  if (!isModuleEnabled(config, "landings")) return {};
  return pageMetadata({
    config,
    locale,
    path: "/learn",
    title: t("index.title"),
    description: t("index.intro"),
  });
}

export default async function LearnIndexPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);

  const [t, config] = await Promise.all([getTranslations("landings"), getSiteConfig()]);
  if (!isModuleEnabled(config, "landings")) notFound();

  const guides = publishedEditorialLandings(config, locale, "area");
  const products = await getLearnIndexProducts(locale);
  const groups = groupLearnProducts(
    products,
    {
      category: (item) => item.category.slug,
      name: (slug) =>
        products.find((item) => item.category.slug === slug)?.category.name ?? "",
      title: (item) => item.name,
    },
    instanceRegistry.landings?.productGroupOrder ?? [],
    locale,
  );

  return (
    <div>
      <header>
        <h1 className="text-3xl font-extrabold tracking-tight">{t("index.title")}</h1>
        <p className="mt-2 text-gray-600 dark:text-gray-400">{t("index.intro")}</p>
      </header>

      {guides.length === 0 && groups.length === 0 && (
        <p className="mt-10 text-gray-500">{t("index.empty")}</p>
      )}

      {guides.length > 0 && (
        <section className="mt-10" aria-labelledby="learn-guides">
          <h2 id="learn-guides" className="text-xl font-bold">
            {t("index.guides")}
          </h2>
          <ul className="mt-4 grid gap-4 sm:grid-cols-2">
            {guides.map((guide) => (
              <li
                key={guide.slug}
                className="rounded-xl border border-gray-200 p-4 dark:border-gray-800"
              >
                <Link href={guide.href} className="block">
                  <span className="block font-semibold">{guide.title}</span>
                  {guide.description && (
                    <span className="mt-1 block text-sm text-gray-600 dark:text-gray-400">
                      {guide.description}
                    </span>
                  )}
                  <span className="mt-2 block text-sm font-semibold text-brand dark:text-accent">
                    {t("index.read")}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      {groups.map((group) => (
        <section key={group.slug || "sin-categoria"} className="mt-10">
          <h2 className="text-xl font-bold">{group.name || t("index.products")}</h2>
          <ul className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {group.items.map((item) => (
              <li
                key={item.slug}
                className="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800"
              >
                <Link href={item.href} className="block h-full">
                  {item.image && (
                    <span className="relative block aspect-square bg-gray-100 dark:bg-gray-800">
                      <CmsImage
                        src={item.image.sourceUrl}
                        alt=""
                        fill
                        sizes="(max-width: 640px) 50vw, 25vw"
                      />
                    </span>
                  )}
                  <span className="block p-3 font-medium">{item.name}</span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}
