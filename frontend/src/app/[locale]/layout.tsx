import type { Metadata } from "next";
import { Inter } from "next/font/google";
import { notFound } from "next/navigation";
import { NextIntlClientProvider } from "next-intl";
import { getMessages, getTranslations, setRequestLocale } from "next-intl/server";
import { routing } from "@/i18n/routing";
import { Link } from "@/i18n/navigation";
import "../globals.css";
import { CartProvider } from "@/components/cart/cart-context";
import { CartIndicator } from "@/components/cart/cart-indicator";
import { AnalyticsProvider } from "@/components/analytics/analytics-provider";
import { LocaleSwitcher } from "@/components/i18n/locale-switcher";
import { ThemeTokens } from "@/components/ui/theme-tokens";
import { getSiteConfig } from "@/lib/config";

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
});

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const [t, config] = await Promise.all([
    getTranslations({ locale, namespace: "site" }),
    getSiteConfig(),
  ]);

  return {
    title: {
      default: t("name"),
      template: `%s · ${t("name")}`,
    },
    description: t("description"),
    metadataBase: new URL(config.brand.url || "http://localhost:3000"),
    alternates: {
      canonical: locale === routing.defaultLocale ? "/" : `/${locale}`,
      languages: { es: "/", en: "/en", "x-default": "/" },
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
      creator: config.social.twitter || undefined,
    },
    ...(config.seo.google_site_verification && {
      verification: { google: config.seo.google_site_verification },
    }),
  };
}

async function JsonLd() {
  const config = await getSiteConfig();
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "WebSite",
    name: config.brand.name,
    description: config.brand.description,
    url: config.brand.url,
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
  setRequestLocale(locale);

  const messages = await getMessages();
  const tNav = await getTranslations("nav");
  const tSite = await getTranslations("site");

  return (
    <html lang={locale} className={inter.variable}>
      <body className="min-h-screen font-sans antialiased">
        {/* Design tokens desde el panel de configuración (React 19 lo eleva a <head>). */}
        <ThemeTokens />
        <NextIntlClientProvider messages={messages}>
          <AnalyticsProvider>
            <CartProvider>
              <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-white"
              >
                {tNav("skipToContent")}
              </a>
              <header className="border-b border-gray-200 bg-white/80 backdrop-blur dark:border-gray-800 dark:bg-black/50">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                  <Link
                    href="/"
                    className="text-lg font-bold tracking-tight"
                    aria-label={tSite("name")}
                  >
                    {tSite("name")}
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

              <main id="main-content" className="mx-auto max-w-5xl px-6 py-12">
                {children}
              </main>

              <footer className="border-t border-gray-200 py-8 text-sm text-gray-500 dark:border-gray-800">
                <div className="mx-auto max-w-5xl space-y-3 px-6 text-center">
                  <nav
                    aria-label={tNav("legal")}
                    className="flex flex-wrap justify-center gap-x-5 gap-y-2"
                  >
                    <Link href="/legal/privacy" className="hover:text-brand">{tNav("privacy")}</Link>
                    <Link href="/legal/cookies" className="hover:text-brand">{tNav("cookies")}</Link>
                    <Link href="/legal/terms"   className="hover:text-brand">{tNav("terms")}</Link>
                    <Link href="/legal/returns" className="hover:text-brand">{tNav("returns")}</Link>
                  </nav>
                  <p>{tSite("footer")}</p>
                </div>
              </footer>
            </CartProvider>
          </AnalyticsProvider>
        </NextIntlClientProvider>
        <JsonLd />
      </body>
    </html>
  );
}
