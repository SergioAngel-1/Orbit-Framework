import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getSiteConfig } from "@/lib/config";
import { pageMetadata } from "@/lib/seo/page-metadata";
import { buildPageBreadcrumbJsonLd } from "@/lib/seo/breadcrumbs";
import { buildLocalBusinessJsonLd } from "@/lib/seo/jsonld";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { ContactForm } from "@/components/forms/contact-form";

export const revalidate = 300;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "contact" }),
    getSiteConfig(),
  ]);
  return pageMetadata({
    config,
    locale,
    path: "/contact",
    title: t("title"),
    description: config.brand.description,
    view: "contact",
  });
}

export default async function ContactPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, tNav, config] = await Promise.all([
    getTranslations("contact"),
    getTranslations("nav"),
    getSiteConfig(),
  ]);
  const jsonLd = [
    buildPageBreadcrumbJsonLd(config, locale, tNav("home"), [
      { name: t("title"), href: "/contact" },
    ]),
    // Una entidad por sede configurada (Legal → Sedes), con el tipo de negocio
    // del panel: aquí las sedes ya son contenido visible.
    ...buildLocalBusinessJsonLd(config),
  ];

  return (
    <div>
      {jsonLd.map((node, i) => (
        <script
          key={i}
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: safeJsonLd(node) }}
        />
      ))}
      <h1 className="mb-8 text-3xl font-extrabold tracking-tight">{t("title")}</h1>
      <ContactForm
        email={config.legal.email || undefined}
        socials={{
          facebook: config.social.facebook || undefined,
          instagram: config.social.instagram || undefined,
          youtube: config.social.youtube || undefined,
        }}
      />
    </div>
  );
}
