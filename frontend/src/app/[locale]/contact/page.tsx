import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { getSiteConfig } from "@/lib/config";
import { alternatesFor } from "@/lib/seo/urls";
import { ContactForm } from "@/components/forms/contact-form";

export const revalidate = 300;

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "contact" });
  return {
    title: t("title"),
    alternates: alternatesFor("/contact", locale),
  };
}

export default async function ContactPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, config] = await Promise.all([getTranslations("contact"), getSiteConfig()]);

  return (
    <div>
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
