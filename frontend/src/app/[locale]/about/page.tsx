import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getSiteConfig } from "@/lib/config";
import { alternatesFor } from "@/lib/seo/urls";
import { buildPersonJsonLd } from "@/lib/seo/jsonld";

export const revalidate = 300;

function parseList(raw: string): string[] {
  return raw
    .split(/[\n,]/)
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "about" }),
    getSiteConfig(),
  ]);
  return {
    title: t("title"),
    description: config.brand.description?.slice(0, 160),
    alternates: alternatesFor("/about", locale),
  };
}

export default async function AboutPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, config] = await Promise.all([getTranslations("about"), getSiteConfig()]);

  const expertise = parseList(config.seo.knows_about);
  const person = buildPersonJsonLd(config);
  const heading = config.legal.company || config.brand.name;

  return (
    <div className="max-w-3xl">
      {person && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify(person) }}
        />
      )}

      <h1 className="mb-4 text-3xl font-extrabold tracking-tight">{t("title")}</h1>

      {config.brand.description && (
        <p className="mb-8 text-lg text-gray-600 dark:text-gray-300">
          {config.brand.description}
        </p>
      )}

      <dl className="space-y-4">
        <div>
          <dt className="text-sm font-semibold text-gray-500 dark:text-gray-400">
            {t("company")}
          </dt>
          <dd className="text-gray-900 dark:text-gray-100">{heading}</dd>
        </div>

        {config.seo.founding_date && (
          <div>
            <dt className="text-sm font-semibold text-gray-500 dark:text-gray-400">
              {t("founded")}
            </dt>
            <dd className="text-gray-900 dark:text-gray-100">
              {config.seo.founding_date}
            </dd>
          </div>
        )}

        {config.legal.address && (
          <div>
            <dt className="text-sm font-semibold text-gray-500 dark:text-gray-400">
              {t("location")}
            </dt>
            <dd className="text-gray-900 dark:text-gray-100">{config.legal.address}</dd>
          </div>
        )}

        {expertise.length > 0 && (
          <div>
            <dt className="text-sm font-semibold text-gray-500 dark:text-gray-400">
              {t("expertise")}
            </dt>
            <dd className="mt-1 flex flex-wrap gap-2">
              {expertise.map((topic) => (
                <span
                  key={topic}
                  className="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200"
                >
                  {topic}
                </span>
              ))}
            </dd>
          </div>
        )}

        {config.legal.email && (
          <div>
            <dt className="text-sm font-semibold text-gray-500 dark:text-gray-400">
              {t("contact")}
            </dt>
            <dd>
              <a
                href={`mailto:${config.legal.email}`}
                className="text-brand hover:underline"
              >
                {config.legal.email}
              </a>
            </dd>
          </div>
        )}
      </dl>

      {config.seo.founder_name && (
        <section className="mt-10 border-t border-gray-200 pt-6 dark:border-gray-800">
          <h2 className="mb-2 text-xl font-bold">{t("team")}</h2>
          <p className="text-gray-900 dark:text-gray-100">
            <span className="font-semibold">{config.seo.founder_name}</span>
            {config.seo.founder_role && (
              <span className="text-gray-500 dark:text-gray-400">
                {" "}
                · {config.seo.founder_role}
              </span>
            )}
          </p>
          {config.seo.founder_url && (
            <a
              href={config.seo.founder_url}
              className="text-sm text-brand hover:underline"
              rel="noopener noreferrer"
              target="_blank"
            >
              {config.seo.founder_url}
            </a>
          )}
        </section>
      )}
    </div>
  );
}
