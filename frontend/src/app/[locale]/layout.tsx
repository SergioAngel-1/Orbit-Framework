import type { Metadata } from "next";
import { Inter } from "next/font/google";
import { notFound } from "next/navigation";
import { NextIntlClientProvider } from "next-intl";
import { getMessages, getTranslations, setRequestLocale } from "next-intl/server";
import { routing } from "@/i18n/routing";
import { Link } from "@/i18n/navigation";
import "../globals.css";
import { CartProvider }       from "@/components/cart/cart-context";
import { CartIndicator }      from "@/components/cart/cart-indicator";
import { CartDrawer }         from "@/components/cart/cart-drawer";
import { AnalyticsProvider }  from "@/components/analytics/analytics-provider";
import { LocaleSwitcher }     from "@/components/i18n/locale-switcher";
import { DarkModeToggle, DarkModeScript } from "@/components/ui/dark-mode-toggle";
import { ThemeTokens }        from "@/components/ui/theme-tokens";
import { getSiteConfig }      from "@/lib/config";
import { buildSiteGraph } from "@/lib/seo/jsonld";

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

  const siteName = config.brand.name || t("name");

  // Plantilla de título configurable (Control Center → SEO & GEO).
  // %site% se sustituye aquí; %s lo resuelve Next con el título de cada página.
  const titleTemplate = (config.seo.title_template || "%s · %site%").replaceAll(
    "%site%",
    siteName,
  );

  // Indexación por defecto según el módulo SEO & GEO (index/follow vs noindex).
  const noindex = config.seo.robots === "noindex,nofollow";

  // Imagen OG: dinámica desde la marca (/api/og) o URL personalizada del panel.
  const ogImage =
    config.seo.default_og === "custom" && config.brand.og_image
      ? config.brand.og_image
      : "/api/og";

  return {
    title: {
      default: siteName,
      template: titleTemplate,
    },
    description: t("description"),
    metadataBase: new URL(config.brand.url || "http://localhost:3000"),
    robots: { index: !noindex, follow: !noindex },
    alternates: {
      canonical: locale === routing.defaultLocale ? "/" : `/${locale}`,
      languages: { es: "/", en: "/en", "x-default": "/" },
    },
    openGraph: {
      title: siteName,
      description: t("description"),
      siteName,
      type: "website",
      locale,
      images: [{ url: ogImage, width: 1200, height: 630, alt: siteName }],
    },
    twitter: {
      card: "summary_large_image",
      title: siteName,
      description: t("description"),
      creator: config.social.twitter || undefined,
      images: [ogImage],
    },
    ...(config.seo.google_site_verification && {
      verification: { google: config.seo.google_site_verification },
    }),
  };
}

async function JsonLd() {
  const config = await getSiteConfig();
  const graph = buildSiteGraph(config);
  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: JSON.stringify(graph) }}
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
  const tNav     = await getTranslations("nav");
  const tSite    = await getTranslations("site");

  return (
    // suppressHydrationWarning necesario para el script de modo oscuro que
    // añade/quita .dark en <html> antes de que React hidrate.
    <html lang={locale} className={inter.variable} suppressHydrationWarning>
      <head>
        {/* Evita FOUC: aplica .dark antes del primer paint */}
        <DarkModeScript />
      </head>
      <body className="min-h-screen bg-[--background] font-sans text-[--foreground] antialiased">
        {/* Design tokens dinámicos desde el panel de configuración */}
        <ThemeTokens />
        <NextIntlClientProvider messages={messages}>
          <AnalyticsProvider>
            <CartProvider>
              {/* Skip link de accesibilidad */}
              <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-white"
              >
                {tNav("skipToContent")}
              </a>

              {/* Navegación */}
              <header className="border-b border-gray-200 bg-white/80 backdrop-blur dark:border-gray-800 dark:bg-black/50">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                  <Link
                    href="/"
                    className="text-lg font-bold tracking-tight"
                    aria-label={tSite("name")}
                  >
                    {tSite("name")}
                  </Link>
                  <nav className="flex items-center gap-4 text-sm font-medium text-gray-600 dark:text-gray-300">
                    <Link href="/products" className="transition-colors hover:text-brand">
                      {tNav("store")}
                    </Link>
                    <Link href="/blog" className="transition-colors hover:text-brand">
                      {tNav("blog")}
                    </Link>
                    <Link href="/about" className="transition-colors hover:text-brand">
                      {tNav("about")}
                    </Link>
                    <Link href="/account" className="transition-colors hover:text-brand">
                      {tNav("account")}
                    </Link>
                    <CartIndicator />
                    <LocaleSwitcher />
                    <DarkModeToggle className="text-gray-600 dark:text-gray-300" />
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

              {/* CartDrawer: panel deslizante (fuera del flujo principal) */}
              <CartDrawer />
            </CartProvider>
          </AnalyticsProvider>
        </NextIntlClientProvider>
        <JsonLd />
      </body>
    </html>
  );
}
