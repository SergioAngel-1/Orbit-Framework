import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { routing } from "@/i18n/routing";

const SLUGS = ["privacy", "cookies", "terms", "returns"] as const;
type LegalSlug = (typeof SLUGS)[number];

interface Section {
  heading: string;
  body: string;
}

/** Pre-genera las 4 páginas legales por idioma (SSG). */
export function generateStaticParams() {
  return routing.locales.flatMap((locale) => SLUGS.map((slug) => ({ locale, slug })));
}

function isLegalSlug(slug: string): slug is LegalSlug {
  return (SLUGS as readonly string[]).includes(slug);
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}): Promise<Metadata> {
  const { locale, slug } = await params;
  if (!isLegalSlug(slug)) return {};
  const t = await getTranslations({ locale, namespace: "legal" });
  return { title: t(`${slug}.title`) };
}

export default async function LegalPage({
  params,
}: {
  params: Promise<{ locale: string; slug: string }>;
}) {
  const { locale, slug } = await params;
  if (!isLegalSlug(slug)) notFound();
  setRequestLocale(locale);

  const t = await getTranslations("legal");
  const sections = t.raw(`${slug}.sections`) as Section[];

  return (
    <article className="mx-auto max-w-2xl">
      <h1 className="text-3xl font-extrabold tracking-tight">{t(`${slug}.title`)}</h1>
      <p className="mt-2 text-sm text-gray-500">{t("disclaimer")}</p>

      <div className="mt-8 space-y-8">
        {sections.map((section) => (
          <section key={section.heading}>
            <h2 className="text-lg font-bold">{section.heading}</h2>
            <p className="mt-2 leading-relaxed text-gray-700 dark:text-gray-300">
              {section.body}
            </p>
          </section>
        ))}
      </div>
    </article>
  );
}
