import type { Metadata } from "next";
import { Inter } from "next/font/google";
import { notFound } from "next/navigation";
import { NextIntlClientProvider } from "next-intl";
import { getMessages, getTranslations, setRequestLocale } from "next-intl/server";
import { routing } from "@/i18n/routing";
import "../globals.css";
import { CartProvider } from "@/components/cart/cart-context";
import { CartDrawer } from "@/components/cart/cart-drawer";
import { AnalyticsProvider } from "@/components/analytics/analytics-provider";
import { DarkModeScript } from "@/components/ui/dark-mode-toggle";
import { ThemeTokens } from "@/components/ui/theme-tokens";
import { SiteHeader } from "@/components/layout/site-header";
import { SiteFooter } from "@/components/layout/site-footer";
import { getSiteConfig } from "@/lib/config";
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
  const tNav = await getTranslations("nav");
  const config = await getSiteConfig();

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
              <SiteHeader logoUrl={config.brand.logo || undefined} />

              <main id="main-content" className="mx-auto max-w-5xl px-6 py-12">
                {children}
              </main>

              <SiteFooter />

              {/* CartDrawer: panel deslizante (fuera del flujo principal) */}
              <CartDrawer couponsEnabled={config.ecommerce.coupons_enabled} />
            </CartProvider>
          </AnalyticsProvider>
        </NextIntlClientProvider>
        <JsonLd />
      </body>
    </html>
  );
}
