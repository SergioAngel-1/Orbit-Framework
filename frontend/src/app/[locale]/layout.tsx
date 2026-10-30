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
import { ColorSchemeHead, colorSchemeHtmlProps } from "@/components/ui/color-scheme";
import { ThemeTokens } from "@/components/ui/theme-tokens";
import { SiteHeader } from "@/components/layout/site-header";
import { SiteFooter } from "@/components/layout/site-footer";
import { getSiteConfig } from "@/lib/config";
import { resolveColorScheme } from "@/lib/config/color-scheme";
import { isModuleEnabled } from "@/lib/config/modules";
import { CurrencyProvider } from "@/components/ui/currency-context";
import { getMenu } from "@/lib/navigation/menu";
import { brandDescription } from "@/lib/config/brand";
import { buildSiteGraph } from "@/lib/seo/jsonld";
import { siteOpenGraph, siteTwitter } from "@/lib/seo/opengraph";
import { absoluteLocalized } from "@/lib/seo/urls";
import { resolveTitleTemplate } from "@/lib/seo/title";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import { indexingBlockedByEnv } from "@/lib/seo/indexing";
import { pickClientMessages } from "@/lib/i18n/client-messages";
import { cn } from "@/lib/utils";

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
  // La descripción de marca sale del CMS (Control Center → Marca), igual que el
  // nombre y la URL. `site.description` de i18n es el texto de plantilla del
  // framework: si se usara aquí, TODA página que no declare su propio
  // `openGraph` lo heredaría al compartirse por WhatsApp o redes.
  const siteDescription = brandDescription(config, locale) || t("description");

  // Plantilla de título configurable (Control Center → SEO).
  // %site% se sustituye aquí; %s lo resuelve Next con el título de cada página.
  const titleTemplate = resolveTitleTemplate(config.seo.title_template, siteName);

  // Indexación por defecto según la pestaña SEO (index/follow vs noindex), o la
  // guarda de entorno `HWE_NOINDEX` (staging). En páginas estáticas/ISR esto se
  // hornea en el build: en ejecución, la guarda la garantiza `X-Robots-Tag` (proxy).
  const noindex = config.seo.robots === "noindex,nofollow" || indexingBlockedByEnv();

  return {
    title: {
      default: siteName,
      template: titleTemplate,
    },
    description: siteDescription,
    metadataBase: new URL(config.brand.url || "http://localhost:3000"),
    robots: { index: !noindex, follow: !noindex },
    // Sin `alternates` aquí a propósito: Next los HEREDA en cada página que no
    // declare los suyos, y todas las vistas sin canónica propia (privadas,
    // 404) saldrían con la de la home. La home y cada página indexable la
    // declaran con `pageMetadata` / `alternatesFor`.
    // Los campos de SITIO (siteName, locale, alternateLocale, imagen por
    // defecto) salen del mismo helper que usa cada página: Next reemplaza el
    // objeto `openGraph` entero cuando una página declara el suyo, así que
    // tenerlos en un solo sitio es lo que evita que se pierdan por el camino.
    //
    // Aquí NO van `title` ni `description` a propósito: Next los resuelve desde
    // los de la propia página cuando el objeto no los trae, y este objeto lo
    // HEREDA toda página que no declare `openGraph`. Fijándolos aquí, la tienda,
    // el blog, el contacto y los legales se compartirían todos con el titular y
    // la descripción de la home. Tampoco `url`: el layout no conoce la ruta;
    // cada página indexable pasa la suya (`siteOpenGraph(config, locale, canónica)`).
    openGraph: siteOpenGraph(config, locale),
    twitter: siteTwitter(config, locale),
    ...((config.seo.google_site_verification || config.seo.bing_site_verification) && {
      verification: {
        ...(config.seo.google_site_verification && {
          google: config.seo.google_site_verification,
        }),
        ...(config.seo.bing_site_verification && {
          other: { "msvalidate.01": config.seo.bing_site_verification },
        }),
      },
    }),
  };
}

async function JsonLd({ locale }: { locale: string }) {
  const config = await getSiteConfig();
  // El responsable/fundador es una entidad del grafo cuya `url` es la página
  // «Sobre nosotros», donde se publica con su cargo y su perfil.
  const founderPageUrl = (config.seo.founder_name || "").trim()
    ? absoluteLocalized(config.brand.url, "/about", locale)
    : null;
  const graph = buildSiteGraph(config, locale, { founderPageUrl });
  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: safeJsonLd(graph) }}
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
  const primaryMenu = await getMenu("primary", locale);
  const colorScheme = resolveColorScheme(config);
  const { className: schemeClass, ...schemeProps } = colorSchemeHtmlProps(colorScheme);

  return (
    // suppressHydrationWarning necesario para el script de modo oscuro que
    // añade/quita .dark en <html> antes de que React hidrate.
    <html
      lang={locale}
      className={cn(inter.variable, schemeClass)}
      {...schemeProps}
      suppressHydrationWarning
    >
      <head>
        {/* Evita FOUC: esquema fijo o script que aplica .dark antes del primer paint */}
        <ColorSchemeHead scheme={colorScheme} />
      </head>
      <body className="min-h-screen bg-[--background] font-sans text-[--foreground] antialiased">
        {/* Design tokens dinámicos desde el panel de configuración */}
        <ThemeTokens />
        <NextIntlClientProvider messages={pickClientMessages(messages)}>
          <AnalyticsProvider
            config={config.integrations}
            plausibleHost={process.env.PLAUSIBLE_HOST || undefined}
            ledger={isModuleEnabled(config, "consent-log")}
          >
            <CurrencyProvider
              currency={config.ecommerce.currency || "EUR"}
              decimals={config.ecommerce.price_decimals}
            >
              <CartProvider>
                {/* Skip link de accesibilidad */}
                <a
                  href="#main-content"
                  className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-brand focus:px-4 focus:py-2 focus:text-white dark:focus:bg-accent dark:focus:text-gray-950"
                >
                  {tNav("skipToContent")}
                </a>

                {/* Navegación */}
                <SiteHeader
                  logoUrl={config.brand.logo || undefined}
                  items={primaryMenu}
                  themeToggle={colorScheme === "system"}
                />

                <main id="main-content" className="mx-auto max-w-5xl px-6 py-12">
                  {children}
                </main>

                <SiteFooter />

                {/* CartDrawer: panel deslizante (fuera del flujo principal) */}
                <CartDrawer couponsEnabled={config.ecommerce.coupons_enabled} />
              </CartProvider>
            </CurrencyProvider>
          </AnalyticsProvider>
        </NextIntlClientProvider>
        <JsonLd locale={locale} />
      </body>
    </html>
  );
}
