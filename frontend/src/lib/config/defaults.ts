import type { SiteConfig } from "./types";

/**
 * Valores por defecto que se usan cuando WordPress no está disponible
 * (build offline, first cold start, red no reachable).
 * Deben coincidir con los `'default'` del Schema.php de WordPress.
 */
export const CONFIG_DEFAULTS: SiteConfig = {
  brand: {
    name: "HeadlessWP",
    tagline: "Headless WooCommerce Template",
    description: "Tienda headless construida con Next.js y WooCommerce.",
    url: process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000",
    locale: "es",
    og_image: "/og-default.jpg",
  },
  social: {
    twitter: "@headlesswp",
    instagram: "",
    facebook: "",
    linkedin: "",
    youtube: "",
    wikipedia: "",
    wikidata: "",
  },
  legal: {
    company: "Headless Web Ecosystem Inc.",
    nif: "",
    email: "hello@headlesswp.com",
    address: "",
  },
  design: {
    colors: {
      brand: "#2563eb",
      brand_dark: "#1e40af",
      brand_light: "#3b82f6",
      secondary: "#16a34a",
      secondary_dark: "#15803d",
      accent: "#f59e0b",
      surface: "#f8fafc",
      background: "#ffffff",
      foreground: "#0a0a0a",
    },
    typography: {
      font_sans: "Inter",
      font_url: "",
      font_heading: "",
      font_heading_url: "",
    },
  },
  ecommerce: {
    currency: "EUR",
    country: "ES",
    products_per_page: "12",
    reviews_enabled: false,
    wishlist_enabled: false,
    coupons_enabled: false,
    search_enabled: true,
  },
  integrations: {
    analytics_provider: "none",
    analytics_id: "",
  },
  seo: {
    title_template: "%s · %site%",
    robots: "index,follow",
    google_site_verification: "",
    default_og: "auto",
    product_brand: "",
    shipping_amount: "",
    return_days: "",
    return_category: "finite",
    organization_logo: "",
    founding_date: "",
    knows_about: "",
    founder_name: "",
    founder_role: "",
    founder_url: "",
  },
  geo: {
    ai_crawlers: "allow",
    llms_txt_enabled: true,
    faq: "",
    content_signal: true,
  },
};
