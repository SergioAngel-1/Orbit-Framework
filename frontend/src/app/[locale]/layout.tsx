import type { Metadata } from "next";
import { Inter } from "next/font/google";
import { notFound } from "next/navigation";
import { NextIntlClientProvider } from "next-intl";
import {
  getMessages,
  getTranslations,
  setRequestLocale,
} from "next-intl/server";
import { siteConfig } from "@/config/site";
import { routing } from "@/i18n/routing";
import { Link } from "@/i18n/navigation";
import "../globals.css";
import { CartProvider } from "@/components/cart/cart-context";
import { CartIndicator } from "@/components/cart/cart-indicator";
import { AnalyticsProvider } from "@/components/analytics/analytics-provider";
import { LocaleSwitcher } from "@/components/i18n/locale-switcher";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
});

/** Pre-genera una variante estática por idioma. */
export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "site" });

  return {
    title: {
      default: t("name"),
      template: `%s · ${t("name")}`,
    },
    description: t("description"),
    metadataBase: new URL(siteConfig.url),
    alternates: {
      canonical: locale === routing.defaultLocale ? "/" : `/${locale}`,
      languages: {
        es: "/",
        en: "/en",
        "x-default": "/",
      },
    },
    openGraph: {
      title: t("name"),
      description: t("description"),
      siteName: t("name"),
      type: "website",
      locale,
    },
    twitter: {
      card: "summary_large_image",
      title: t("name"),
      description: t("description"),
      creator: siteConfig.social.twitter,
    },
  };
}

function JsonLd() {
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "WebSite",
    name: siteConfig.name,
    description: siteConfig.description,
    url: siteConfig.url,
  };

  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
    />
  );
}

export default async function LocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  if (!routing.locales.includes(locale as (typeof routing.locales)[number])) {
    notFound();
  }
  // Habilita el renderizado estático para este locale.
  setRequestLocale(locale);

  const messages = await getMessages();
  const tNav = await getTranslations("nav");
  const tSite = await getTranslations("site");

  return (
    <html lang={locale} className={inter.variable}>
      <body className="min-h-screen font-sans antialiased">
        <NextIntlClientProvider messages={messages}>
          <AnalyticsProvider>
            <CartProvider>
              <header className="border-b border-gray-200 bg-white/80 backdrop-blur dark:border-gray-800 dark:bg-black/50">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                  <Link href="/" className="text-lg font-bold tracking-tight">
                    Headless<span className="text-brand">WP</span>
                  </Link>
                  <nav className="flex items-center gap-5 text-sm font-medium text-gray-600 dark:text-gray-300">
                    <Link href="/products" className="transition-colors hover:text-brand">
                      {tNav("store")}
                    </Link>
                    <Link href="/account" className="transition-colors hover:text-brand">
                      {tNav("account")}
                    </Link>
                    <CartIndicator />
                    <LocaleSwitcher />
                  </nav>
                </div>
              </header>

              <main className="mx-auto max-w-5xl px-6 py-12">{children}</main>

              <footer className="border-t border-gray-200 py-8 text-center text-sm text-gray-500 dark:border-gray-800">
                <div className="mx-auto max-w-5xl px-6">{tSite("footer")}</div>
              </footer>
            </CartProvider>
          </AnalyticsProvider>
        </NextIntlClientProvider>
        <JsonLd />
      </body>
    </html>
  );
}
