export const siteConfig = {
  name: "HeadlessWP",
  tagline: "Headless WooCommerce Template",
  description:
    "Frontend Next.js (App Router) connected to WordPress via WPGraphQL and WooCommerce.",

  url: process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000",
  locale: "es",

  brand: {
    primary: "#2563eb",
  },

  social: {
    twitter: "@headlesswp",
  },

  legal: {
    email: "hello@headlesswp.com",
    company: "Headless Web Ecosystem Inc.",
  },

  defaultOgImage: "/og-default.jpg",
};
